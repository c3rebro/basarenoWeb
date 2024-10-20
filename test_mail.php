<?php 
// Example usage
$verification_token = bin2hex(random_bytes(16));
$hash = bin2hex(random_bytes(16)); // Assuming you have a way to generate this
$given_name = 'Max'; // Example given name
$family_name = 'Müller'; // Example family name with special character
$seller_id = '12345'; // Example seller ID
$to = 'ping@tools.mxtoolbox.com'; // Example email address

$verification_link = "/verify.php?token=$verification_token&hash=$hash";
$subject = "Verifizierung Ihrer Verkäufer-ID: $seller_id";
$from = "badmin@basar-horrheim.de";
$subject = "Verkäufernummer";

$message = "<html><body>";
$message .= "<p>Hallo $given_name $family_name.</p>";
$message .= "<p></p>";
$message .= "<p>Bitte klicken Sie auf den folgenden Link, um Ihre Verkäufer-ID zu verifizieren: <a href='$verification_link'>$verification_link</a></p>";
$message .= "<p>Nach der Verifizierung können Sie Ihre Artikel erstellen und Etiketten drucken:</p>";
$message .= "<p><a href='" . "/seller_products.php?seller_id=$seller_id&hash=$hash'>Artikel erstellen</a></p>";
$message .= "<p><strong>WICHTIG:</strong> Diese Mail und die enthaltenen Links sind nur für Sie bestimmt. Geben Sie diese nicht weiter.</p>";
$message .= "</body></html>";

$headers = "From: " . from ."\n";
$headers .= "Reply-to: " . from ."\n";
$headers .= "MIME-Version: 1.0\n";
$headers .= "Content-Type: text/html; charset=utf-8\n";

mail($to,$subject,$message, $headers, "-f " . from);
?>