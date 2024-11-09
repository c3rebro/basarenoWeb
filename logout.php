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

// Redirect based on the user's role
if ($role === 'admin') {
    header("Location: admin_login.php");
} elseif ($role === 'cashier') {
    header("Location: cashier_login.php");
} else {
    // Default redirect if no specific role is found
    header("Location: index.php");
}

exit;
?>