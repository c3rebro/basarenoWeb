<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$to = 'mail@shansen-online.de';
$subject = 'Test Email';
$message = 'This is a test email.';
$headers = 'From: fadmin@foerderverein-horrheim.de' . "\r" .
           'Reply-To: fadmin@foerderverein-horrheim.de' . "\r" .
           'X-Mailer: PHP/' . phpversion();

if (mail($to, $subject, $message, $headers)) {
    echo 'Email sent successfully!';
} else {
    echo 'Email sending failed. Error: ' . error_get_last()['message'];
}
?>