<?php
session_start();
require_once 'config.php';

// Set default sorting options
$sortBy = isset($_GET['sortBy']) ? $_GET['sortBy'] : 'startDate';
$sortOrder = isset($_GET['sortOrder']) ? $_GET['sortOrder'] : 'DESC';

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

// Function to check for active bazaars
function has_active_bazaar($conn) {
    $current_date = date('Y-m-d');
    $sql = "SELECT COUNT(*) as count FROM bazaar WHERE startDate <= '$current_date'";
    $result = $conn->query($sql)->fetch_assoc();
    return $result['count'] > 0;
}

// Handle bazaar addition
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_bazaar'])) {
    $startDate = $_POST['startDate'];
    $startReqDate = $_POST['startReqDate'];
    $brokerage = $_POST['brokerage'];

    if (empty($startDate) || empty($startReqDate) || empty($brokerage)) {
        $error = "Alle Felder sind erforderlich.";
    } else {
        // Check if a newer bazaar already exists
        $sql = "SELECT COUNT(*) as count FROM bazaar WHERE startDate > '$startDate'";
        $result = $conn->query($sql)->fetch_assoc();
        if ($result['count'] > 0) {
            $error = "Sie können diesen Bazaar nicht erstellen. Ein neuerer Bazaar existiert bereits.";
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

// Handle bazaar removal
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['remove_bazaar'])) {
    $bazaar_id = $_POST['bazaar_id'];

    $sql = "DELETE FROM bazaar WHERE id='$bazaar_id'";
    if ($conn->query($sql) === TRUE) {
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error']);
    }
    exit;
}

// Fetch bazaar details with sorting
$sql = "SELECT * FROM bazaar ORDER BY $sortBy $sortOrder";
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
        <div class="form-row">
            <div class="form-group col-md-3">
                <label for="sortBy">Sortieren nach:</label>
                <select class="form-control" id="sortBy">
                    <option value="startDate" <?php echo $sortBy == 'startDate' ? 'selected' : ''; ?>>Startdatum</option>
                    <option value="id" <?php echo $sortBy == 'id' ? 'selected' : ''; ?>>ID</option>
                </select>
            </div>
            <div class="form-group col-md-3">
                <label for="sortOrder">Reihenfolge:</label>
                <select class="form-control" id="sortOrder">
                    <option value="DESC" <?php echo $sortOrder == 'DESC' ? 'selected' : ''; ?>>Absteigend</option>
                    <option value="ASC" <?php echo $sortOrder == 'ASC' ? 'selected' : ''; ?>>Aufsteigend</option>
                </select>
            </div>
        </div>

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
                <tbody id="bazaarTable">
                    <?php while ($row = $result->fetch_assoc()) { ?>
                        <tr id="bazaar-<?php echo $row['id']; ?>">
                            <td><?php echo htmlspecialchars($row['id']); ?></td>
                            <td><?php echo htmlspecialchars($row['startDate']); ?></td>
                            <td><?php echo htmlspecialchars($row['startReqDate']); ?></td>
                            <td><?php echo htmlspecialchars($row['brokerage'] * 100); ?></td>
                            <td>
                                <button class="btn btn-warning btn-sm" data-toggle="modal" data-target="#editBazaarModal<?php echo $row['id']; ?>">Bearbeiten</button>
                                <button class="btn btn-info btn-sm view-bazaar" data-id="<?php echo $row['id']; ?>" data-toggle="modal" data-target="#viewBazaarModal">Auswertung</button>
                                <button class="btn btn-danger btn-sm remove-bazaar" data-id="<?php echo $row['id']; ?>">Entfernen</button>
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

    <!-- Error Modal -->
    <div class="modal fade" id="errorModal" tabindex="-1" role="dialog" aria-labelledby="errorModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="errorModalLabel">Fehler</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    Sie können diesen Bazaar nicht erstellen. Ein neuerer Bazaar existiert bereits.
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Schließen</button>
                </div>
            </div>
        </div>
    </div>

    <script src="js/jquery-3.7.1.min.js"></script>
    <script src="js/popper.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
    <script>
        $(document).ready(function() {
            // Handle removal of bazaar
            $('.remove-bazaar').on('click', function() {
                var bazaarId = $(this).data('id');
                
                if (confirm('Sind Sie sicher, dass Sie diesen Bazaar entfernen möchten?')) {
                    $.ajax({
                        url: 'admin_manage_bazaar.php',
                        type: 'POST',
                        data: {
                            remove_bazaar: true,
                            bazaar_id: bazaarId
                        },
                        success: function(response) {
                            var data = JSON.parse(response);
                            if (data.status === 'success') {
                                $('#bazaar-' + bazaarId).remove();
                            } else {
                                alert('Fehler beim Entfernen des Bazaars.');
                            }
                        },
                        error: function() {
                            alert('Fehler beim Entfernen des Bazaars.');
                        }
                    });
                }
            });

            // Handle viewing bazaar details
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

            // Handle sorting changes
            $('#sortBy, #sortOrder').on('change', function() {
                var sortBy = $('#sortBy').val();
                var sortOrder = $('#sortOrder').val();
                window.location.href = 'admin_manage_bazaar.php?sortBy=' + sortBy + '&sortOrder=' + sortOrder;
            });
        });
    </script>
</body>
</html>