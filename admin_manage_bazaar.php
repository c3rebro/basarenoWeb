<?php
session_start();
require_once 'config.php';

if (isset($_POST['action']) && $_POST['action'] === 'fetch_bazaar_data') {
    $bazaar_id = $_POST['bazaar_id'];
    $conn = get_db_connection();

    // Fetch products count
    $products_count_all = $conn->query("SELECT COUNT(*) as count FROM products WHERE bazaar_id = $bazaar_id")->fetch_assoc()['count'];
    // Fetch sold products count
    $products_count_sold = $conn->query("SELECT COUNT(*) as count FROM products WHERE bazaar_id = $bazaar_id AND sold = 1")->fetch_assoc()['count'];
    // Fetch total sum of sold products
    $total_sum_sold = $conn->query("SELECT SUM(price) as total FROM products WHERE bazaar_id = $bazaar_id AND sold = 1")->fetch_assoc()['total'];
    // Fetch brokerage percentage for the bazaar
    $brokerage_percentage = $conn->query("SELECT brokerage FROM bazaar WHERE id = $bazaar_id")->fetch_assoc()['brokerage'];
    // Calculate total brokerage for sold products
    $total_brokerage = $total_sum_sold * $brokerage_percentage;

    $conn->close();

    echo json_encode([
        'products_count_all' => $products_count_all,
        'products_count_sold' => $products_count_sold,
        'total_sum_sold' => number_format($total_sum_sold, 2, ',', '.') . ' €',
        'total_brokerage' => number_format($total_brokerage, 2, ',', '.') . ' €'
    ]);
    exit;
}

// The rest of your PHP code
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || $_SESSION['role'] !== 'admin') {
    header("location: index.php");
    exit;
}

$conn = get_db_connection();
initialize_database($conn);

$error = '';
$success = '';

// Handle bazaar addition
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_bazaar'])) {
    $startDate = $_POST['startDate'];
    $startReqDate = $_POST['startReqDate'];
    $brokerage = $_POST['brokerage'];

    if (empty($startDate) || empty($startReqDate) || empty($brokerage)) {
        $error = "Alle Felder sind erforderlich.";
    } else {
        $brokerage = $brokerage / 100; // Convert percentage to decimal
        $sql = "INSERT INTO bazaar (startDate, startReqDate, brokerage) VALUES ('$startDate', '$startReqDate', '$brokerage')";
        if ($conn->query($sql) === TRUE) {
            $success = "Bazaar erfolgreich hinzugefügt.";
        } else {
            $error = "Fehler beim Hinzufügen des Bazaars: " . $conn->error;
        }
    }
}

// Handle bazaar modification
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_bazaar'])) {
    $bazaar_id = $_POST['bazaar_id'];
    $startDate = $_POST['startDate'];
    $startReqDate = $_POST['startReqDate'];
    $brokerage = $_POST['brokerage'];

    if (empty($startDate) || empty($startReqDate) || empty($brokerage)) {
        $error = "Alle Felder sind erforderlich.";
    } else {
        $brokerage = $brokerage / 100; // Convert percentage to decimal
        $sql = "UPDATE bazaar SET startDate='$startDate', startReqDate='$startReqDate', brokerage='$brokerage' WHERE id='$bazaar_id'";
        if ($conn->query($sql) === TRUE) {
            $success = "Bazaar erfolgreich aktualisiert.";
        } else {
            $error = "Fehler beim Aktualisieren des Bazaars: " . $conn->error;
        }
    }
}

// Fetch bazaar details
$sql = "SELECT * FROM bazaar";
$result = $conn->query($sql);

$conn->close();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Bazaar Verwalten</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <style>
        .form-row {
            margin-bottom: 1rem;
        }
        @media (max-width: 768px) {
            .table-responsive {
                overflow-x: auto;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h2 class="mt-5">Bazaar Verwalten</h2>
        <?php if ($error) { echo "<div class='alert alert-danger'>$error</div>"; } ?>
        <?php if ($success) { echo "<div class='alert alert-success'>$success</div>"; } ?>

        <h3 class="mt-5">Neuen Bazaar hinzufügen</h3>
        <form action="admin_manage_bazaar.php" method="post">
            <div class="form-row">
                <div class="form-group col-md-4">
                    <label for="startDate">Startdatum:</label>
                    <input type="date" class="form-control" id="startDate" name="startDate" required>
                </div>
                <div class="form-group col-md-4">
                    <label for="startReqDate">Anforderungsdatum:</label>
                    <input type="date" class="form-control" id="startReqDate" name="startReqDate" required>
                </div>
                <div class="form-group col-md-4">
                    <label for="brokerage">Provision (%):</label>
                    <input type="number" step="0.01" class="form-control" id="brokerage" name="brokerage" required>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-block" name="add_bazaar">Bazaar hinzufügen</button>
        </form>

        <h3 class="mt-5">Bestehende Bazaars</h3>
        <div class="table-responsive">
            <table class="table table-bordered mt-3">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Startdatum</th>
                        <th>Anforderungsdatum</th>
                        <th>Provision (%)</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $result->fetch_assoc()) { ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['id']); ?></td>
                            <td><?php echo htmlspecialchars($row['startDate']); ?></td>
                            <td><?php echo htmlspecialchars($row['startReqDate']); ?></td>
                            <td><?php echo htmlspecialchars($row['brokerage'] * 100); ?></td>
                            <td>
                                <button class="btn btn-warning btn-sm" data-toggle="modal" data-target="#editBazaarModal<?php echo $row['id']; ?>">Bearbeiten</button>
                                <button class="btn btn-info btn-sm view-bazaar" data-id="<?php echo $row['id']; ?>" data-toggle="modal" data-target="#viewBazaarModal">Auswertung</button>
                                <!-- Edit Bazaar Modal -->
                                <div class="modal fade" id="editBazaarModal<?php echo $row['id']; ?>" tabindex="-1" role="dialog" aria-labelledby="editBazaarModalLabel<?php echo $row['id']; ?>" aria-hidden="true">
                                    <div class="modal-dialog" role="document">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title" id="editBazaarModalLabel<?php echo $row['id']; ?>">Bazaar bearbeiten</h5>
                                                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                                    <span aria-hidden="true">&times;</span>
                                                </button>
                                            </div>
                                            <div class="modal-body">
                                                <form action="admin_manage_bazaar.php" method="post">
                                                    <input type="hidden" name="bazaar_id" value="<?php echo $row['id']; ?>">
                                                    <div class="form-group">
                                                        <label for="startDate<?php echo $row['id']; ?>">Startdatum:</label>
                                                        <input type="date" class="form-control" id="startDate<?php echo $row['id']; ?>" name="startDate" value="<?php echo htmlspecialchars($row['startDate']); ?>" required>
                                                    </div>
                                                    <div class="form-group">
                                                        <label for="startReqDate<?php echo $row['id']; ?>">Anforderungsdatum:</label>
                                                        <input type="date" class="form-control" id="startReqDate<?php echo $row['id']; ?>" name="startReqDate" value="<?php echo htmlspecialchars($row['startReqDate']); ?>" required>
                                                    </div>
                                                    <div class="form-group">
                                                        <label for="brokerage<?php echo $row['id']; ?>">Provision (%):</label>
                                                        <input type="number" step="0.01" class="form-control" id="brokerage<?php echo $row['id']; ?>" name="brokerage" value="<?php echo htmlspecialchars($row['brokerage'] * 100); ?>" required>
                                                    </div>
                                                    <button type="submit" class="btn btn-primary btn-block" name="edit_bazaar">Speichern</button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
        <a href="dashboard.php" class="btn btn-primary btn-block mt-3 mb-5">Zurück zum Dashboard</a>
    </div>

    <!-- View Bazaar Modal -->
    <div class="modal fade" id="viewBazaarModal" tabindex="-1" role="dialog" aria-labelledby="viewBazaarModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewBazaarModalLabel">Bazaar Auswertung</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <form>
                        <div class="form-group">
                            <label for="productsCountAll">Gesamtzahl aller Artikel:</label>
                            <input type="text" class="form-control" id="productsCountAll" readonly>
                        </div>
                        <div class="form-group">
                            <label for="productsCountSold">Davon verkauft:</label>
                            <input type="text" class="form-control" id="productsCountSold" readonly>
                        </div>
                        <div class="form-group">
                            <label for="totalSumSold">Gesamtsumme der verkauften Artikel:</label>
                            <input type="text" class="form-control" id="totalSumSold" readonly>
                        </div>
                        <div class="form-group">
                            <label for="totalBrokerage">Gesamtsumme der Provision:</label>
                            <input type="text" class="form-control" id="totalBrokerage" readonly>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="js/jquery-3.7.1.min.js"></script>
    <script src="js/popper.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
    <script>
        $(document).ready(function() {
            $('.view-bazaar').on('click', function() {
                var bazaar_id = $(this).data('id');
                $.ajax({
                    url: 'admin_manage_bazaar.php',
                    type: 'POST',
                    data: {
                        action: 'fetch_bazaar_data',
                        bazaar_id: bazaar_id
                    },
                    success: function(response) {
                        var data = JSON.parse(response);
                        $('#productsCountAll').val(data.products_count_all);
                        $('#productsCountSold').val(data.products_count_sold);
                        $('#totalSumSold').val(data.total_sum_sold);
                        $('#totalBrokerage').val(data.total_brokerage);
                    }
                });
            });
        });
    </script>
</body>
</html>