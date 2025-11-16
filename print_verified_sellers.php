<?php
// Start session securely
session_start([
    'cookie_secure'  => true,
    'cookie_httponly'=> true,
    'cookie_samesite'=> 'Strict'
]);

require_once 'utilities.php';

$conn = get_db_connection();

// Ensure the user is logged in and has the correct role
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !in_array($_SESSION['role'], ['admin'])) {
    die("<div class='container'><h2>Zugriff verweigert</h2><p>Sie haben keine Berechtigung, diese Seite zu sehen.</p></div>");
}

/** Read UI state: GET -> cookies -> defaults */
$filter  = $_GET['filter']  ?? ($_COOKIE['filter']  ?? 'all');
$sort_by = $_GET['sort_by'] ?? ($_COOKIE['sort_by'] ?? 'seller_number');
$order   = $_GET['order']   ?? ($_COOKIE['order']   ?? 'ASC');

$order = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';

/** Whitelist sortable columns */
$sortable = [
    'seller_number' => 's.seller_number',
    'checkout_id'   => 's.checkout_id',
    'family_name'   => 'ud.family_name',
    'checkout'      => 's.checkout',
];
$sortExpr = $sortable[$sort_by] ?? 's.seller_number';
$secondarySort = '';

if ($sortExpr === 'ud.family_name') {
    $secondarySort = ", ud.given_name $order, s.seller_number $order";
} elseif ($sortExpr === 's.checkout_id') {
    $secondarySort = ", s.seller_number $order";
} elseif ($sortExpr === 's.seller_number') {
    $secondarySort = ", ud.family_name $order, ud.given_name $order";
}

/** Determine bazaar context for current_bazaar filter (same logic as admin page) */
$active_bazaar_id = get_active_or_registering_bazaar_id($conn);
$open_registration_bazaar_id = get_bazaar_id_with_open_registration($conn);
$bazaar_filter_ids = array_values(array_unique(array_filter([
    $active_bazaar_id,
    $open_registration_bazaar_id
])));

/** Build WHERE conditions consistent with admin page */
$where = [];
$params = [];
$types  = '';

switch ($filter) {
    case 'done':
        $where[] = 's.checkout = 1';
        break;
    case 'undone':
        $where[] = 's.checkout = 0';
        break;
    case 'paid':
        $where[] = 's.fee_payed = 1';
        break;
    case 'undone_paid':
        $where[] = 's.checkout = 0';
        $where[] = 's.fee_payed = 1';
        break;
    case 'current_bazaar':
        if (!empty($bazaar_filter_ids)) {
            // Use IN (...) safely by binding each id
            $inPlaceholders = implode(',', array_fill(0, count($bazaar_filter_ids), '?'));
            $where[] = "s.bazaar_id IN ($inPlaceholders)";
            foreach ($bazaar_filter_ids as $bid) {
                $params[] = (int)$bid;
                $types   .= 'i';
            }
        } else {
            $where[] = '1 = 0'; // No bazaar context -> show nothing
        }
        break;
    case 'all':
    default:
        // No extra WHERE (matches admin page behavior for "all")
        break;
}

/** Base query (LEFT JOIN user_details like admin page) */
$sql = "
    SELECT 
        s.seller_number     AS id,
        ud.family_name,
        ud.given_name,
        ud.phone,
        ud.email,
        s.checkout_id
    FROM sellers s
    LEFT JOIN user_details ud ON s.user_id = ud.user_id
";
if (!empty($where)) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= " ORDER BY $sortExpr $order$secondarySort";

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Verkäuferliste (Druck)</title>
    <link rel="stylesheet" href="css/bootstrap.min.css">
	<link rel="stylesheet" href="css/style.css">
    <style>
        @media print { .no-print { display: none; } }
        table.table { font-size: 0.95rem; }
    </style>
</head>
<body>
    <div class="container">
        <h2 class="mt-4">Verkäuferliste (Druck)</h2>
        <p class="text-muted mb-2">
            Filter: <strong><?= htmlspecialchars($filter) ?></strong> •
            Sortierung: <strong><?= htmlspecialchars($sort_by) ?> <?= htmlspecialchars($order) ?></strong>
            <?php if (!empty($bazaar_filter_ids) && $filter === 'current_bazaar'): ?>
                • Basar-IDs: <strong><?= htmlspecialchars(implode(', ', $bazaar_filter_ids)) ?></strong>
            <?php endif; ?>
        </p>

        <?php if ($result->num_rows > 0): ?>
            <table class="table table-bordered mt-3">
                <thead class="thead-light">
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
                            <td><?= htmlspecialchars($row['id'] ?? '') ?></td>
                            <td><?= htmlspecialchars($row['family_name'] ?? '') ?></td>
                            <td><?= htmlspecialchars($row['given_name'] ?? '') ?></td>
                            <td><?= htmlspecialchars($row['phone'] ?? '') ?></td>
                            <td><?= htmlspecialchars($row['email'] ?? '') ?></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>Keine Verkäufer für die aktuelle Auswahl gefunden.</p>
        <?php endif; ?>

        <button class="btn btn-primary no-print" onclick="window.print()">Drucken</button>
    </div>
    <script src="js/bootstrap.min.js"></script>
</body>
</html>
<?php
$conn->close();
