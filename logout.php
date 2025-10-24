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

// Handle both AJAX and normal requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo json_encode(['success' => true, 'message' => 'Abgemeldet']);
    exit();
} else {
    header("Location: login.php");
    exit();
}