<?php
session_start();

// Check if the role is set in the session
if (isset($_SESSION['role'])) {
    $role = $_SESSION['role'];
} else {
    // Default role or redirect if no role is set
    $role = 'guest'; // or handle as needed
}

// Clear the session
$_SESSION = array();
session_destroy();

// Default redirect
header("Location: dashboard.php");

exit;
?>