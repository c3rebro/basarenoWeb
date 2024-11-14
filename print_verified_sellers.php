<?php
require_once 'utilities.php';

$conn = get_db_connection();

$sql = "SELECT id, family_name, given_name, checkout_id, phone, email FROM sellers WHERE verified = 1";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    echo '<div class="container">';
    echo '<h2>Verifizierte Verkäufer</h2>';
    echo '<table class="table table-bordered">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>Verk.Nr.</th>';
    echo '<th>Nachname</th>';
    echo '<th>Vorname</th>';
    echo '<th>Abr.Nr.</th>';
    echo '<th>Telefon</th>';
    echo '<th>E-Mail</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    while ($row = $result->fetch_assoc()) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($row['id']) . '</td>';
        echo '<td>' . htmlspecialchars($row['family_name']) . '</td>';
        echo '<td>' . htmlspecialchars($row['given_name']) . '</td>';
        echo '<td>' . htmlspecialchars($row['checkout_id']) . '</td>';
        echo '<td>' . htmlspecialchars($row['phone']) . '</td>';
        echo '<td>' . htmlspecialchars($row['email']) . '</td>';
        echo '</tr>';
    }
    echo '</tbody>';
    echo '</table>';
    echo '</div>';
} else {
    echo '<div class="container">';
    echo '<h2>Keine verifizierten Verkäufer gefunden.</h2>';
    echo '</div>';
}

$conn->close();
?>