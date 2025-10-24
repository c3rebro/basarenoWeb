<?php
// Start session securely
session_start([
    'cookie_secure' => true,
    'cookie_httponly' => true,
    'cookie_samesite' => 'Strict'
]);

require_once 'utilities.php';

$conn = get_db_connection();

// Ensure the user is logged in and has the correct role
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !in_array($_SESSION['role'], ['admin'])) {
    die("<div class='container'><h2>Zugriff verweigert</h2><p>Sie haben keine Berechtigung, diese Seite zu sehen.</p></div>");
}

// Updated SQL query to reflect new schema (joining sellers with user_details)
$sql = "
    SELECT 
        s.seller_number AS id, 
        ud.family_name, 
        ud.given_name, 
        s.checkout_id, 
        ud.phone, 
        ud.email 
    FROM sellers s
    INNER JOIN user_details ud ON s.user_id = ud.user_id
    WHERE s.seller_verified = 1
    ORDER BY ud.family_name ASC, ud.given_name ASC
";

$stmt = $conn->prepare($sql);
$stmt->execute();
$result = $stmt->get_result();

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Verifizierte Verkäufer</title>
    <link rel="stylesheet" href="css/bootstrap.min.css">
	<link rel="stylesheet" href="css/style.css">
    <style>
        @media print {
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <div class="container">
        <h2 class="mt-4">Verifizierte Verkäufer</h2>
        <?php if ($result->num_rows > 0): ?>
            <table class="table table-bordered mt-3">
                <thead>
                    <tr>
                        <th>Verk.Nr.</th>
                        <th>Nachname</th>
                        <th>Vorname</th>
                        <th>Telefon</th>
                        <th>E-Mail</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['id']) ?></td>
                            <td><?= htmlspecialchars($row['family_name']) ?></td>
                            <td><?= htmlspecialchars($row['given_name']) ?></td>
                            <td><?= htmlspecialchars($row['phone']) ?></td>
                            <td><?= htmlspecialchars($row['email']) ?></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>Keine verifizierten Verkäufer gefunden.</p>
        <?php endif; ?>
        <button class="btn btn-primary no-print" onclick="window.print()">Drucken</button>
    </div>
    <script src="js/bootstrap.min.js"></script>
</body>
</html>
<?php
$conn->close();
?>
