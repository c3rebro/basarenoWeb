<?php
// Start session with secure settings
session_start([
    'cookie_secure' => true,   // Ensure the session cookie is only sent over HTTPS
    'cookie_httponly' => true, // Prevent JavaScript access to the session cookie
    'cookie_samesite' => 'Strict' // Add SameSite attribute for additional CSRF protection
]);

$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce' blob:; style-src 'self' 'nonce-$nonce'; img-src 'self' 'nonce-$nonce' data:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none';");

require_once 'utilities.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'cashier')) {
    header("location: login.php");
    exit;
}

$conn = get_db_connection();

$message = '';
$message_type = 'danger'; // Default message type for errors

$scanned_products = [];
$sum_of_prices = $_SESSION['sum_of_prices'] ?? 0.0;
$groups = $_SESSION['groups'] ?? [];
$buyer_index = $_SESSION['buyer_index'] ?? 1;
$current_bazaar_id = get_current_bazaar_id($conn) === 0 ? get_bazaar_id_with_open_registration($conn) : null;

if (filter_input(INPUT_SERVER, 'REQUEST_METHOD') === 'POST') {	
	if (filter_input(INPUT_POST, 'barcode') !== null || filter_input(INPUT_POST, 'isManualInput') !== null) {
		header('Content-Type: application/json'); // Respond with JSON
		
		if (filter_input(INPUT_POST, 'isManualInput') !== null) {
			$seller_number = filter_input(INPUT_POST, 'manual-seller-number', FILTER_SANITIZE_NUMBER_INT);
			$product_id = filter_input(INPUT_POST, 'manual-product-id', FILTER_SANITIZE_NUMBER_INT);


			if ($current_bazaar_id === null) {
				echo json_encode([
					"status" => "error",
					"message" => "No active bazaar found."
				]);
				exit;
			}

			// Retrieve user_id using seller_number and bazaar_id
			$stmt = $conn->prepare("SELECT user_id FROM sellers WHERE bazaar_id = ? AND seller_number = ?");
			$stmt->bind_param("ii", $current_bazaar_id, $seller_number);
			$stmt->execute();
			$result = $stmt->get_result();

			if ($result->num_rows === 0) {
				echo json_encode([
					"status" => "error",
					"message" => "Invalid seller number for the current bazaar."
				]);
				exit;
			}

			$row = $result->fetch_assoc();
			$user_id = $row['user_id'];

			// Generate the barcode
			$barcode = sprintf("U%04dV%04dA%03d", $user_id, $seller_number, $product_id);
			log_action($conn, $_SESSION['user_id'], "manual input barcode", "Barcode: $barcode");
		} else {
			$barcode = filter_input(INPUT_POST, 'barcode');
			log_action($conn, $_SESSION['user_id'], "Scanned barcode", "Barcode: $barcode");
		}
	
        // Validate barcode format
        if (preg_match('/^U(\d+)V(\d+)A(\d{3})$/', $barcode, $matches)) {
            $user_id_fromQR = (int)$matches[1];
            $seller_number = (int)$matches[2];
            $article_id = (int)$matches[3];

			$stmt = $conn->prepare("
				SELECT 1 
				FROM sellers 
				WHERE user_id = ? AND seller_number = ? AND bazaar_id = ?");
			$stmt->bind_param("iii", $user_id_fromQR, $seller_number, $current_bazaar_id);
			$stmt->execute();
			$result = $stmt->get_result();

			if ($result->num_rows === 0) {
				echo json_encode([
					"status" => "unauthorized",
					"message" => "Seller not authorized for the current bazaar."
				]);
				exit;
			}

			// Check if the product exists
			$stmt = $conn->prepare("
				SELECT id, name, price, sold 
				FROM products 
				WHERE seller_number = ? AND product_id = ?");
			$stmt->bind_param("ii", $seller_number, $article_id);
			$stmt->execute();
			$result = $stmt->get_result();

			if ($result->num_rows === 0) {
				echo json_encode([
					"status" => "not_found",
					"message" => "Product not found."
				]);
				exit;
			}

			$row = $result->fetch_assoc();
	
            $stmt = $conn->prepare("SELECT id, name, price, sold, seller_number FROM products WHERE barcode=? AND bazaar_id=? AND seller_number=?");
            $stmt->bind_param("sii", $barcode, $current_bazaar_id, $seller_number);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $row = $result->fetch_assoc();
                if ($row['sold'] == 1) {
                    //show_toast($nonce, 'Nope. Der Artikel wurde bereits gescannt', 'Warnung', 'warning', 'alreadyScannedToast', 3000);
					echo json_encode([
                        "status" => "already_scanned",
                        "message" => "Product already scanned.",
                        "product" => [
							"id" => $row['id'], // Include product ID
                            "name" => $row['name'],
                            "price" => $row['price'],
                            "sellerNumber" => $row['seller_number']
                        ]
                    ]);
                    log_action($conn, $_SESSION['user_id'], "Product already sold", "Product ID: " . $row['id']);
					exit;
                } else {
                    $formatted_price = number_format($row['price'], 2, ',', '.');
                    //show_toast($nonce, 'Produkt "'.htmlspecialchars($row['name']).'" gescannt.', 'Erfolgreich', 'success', 'scannedSuccessToast', 2000);
                    
                    $stmt = $conn->prepare("UPDATE products SET sold=1 WHERE barcode=?");
                    $stmt->bind_param("s", $barcode);
                    $stmt->execute();
                    log_action($conn, $_SESSION['user_id'], "Product marked as sold", "Product ID: " . $row['id']);

                    if (!isset($groups[0])) {
                        $groups[0] = ['products' => [], 'sum' => 0];
                    }
                    //$groups[0]['products'][] = $row;
                    //$groups[0]['sum'] += $row['price'];

                    //$sum_of_prices += $row['price'];
                    //$_SESSION['sum_of_prices'] = $sum_of_prices;
					
					echo json_encode([
                        "status" => "success",
                        "message" => "Product scanned successfully.",
                        "product" => [
							"id" => $row['id'], // Include product ID
                            "name" => $row['name'],
                            "price" => $row['price'],
                            "sellerNumber" => $row['seller_number']
                        ]
                    ]);
					exit;
                }
            } else {
                //show_modal($nonce, 'Produkt wurde nicht gefunden. Evtl. Sch*** Drucker? -> manuelle Eingabe.', 'danger', 'Nicht gefunden', 'productNotFoundModal');
				echo json_encode([
                    "status" => "not_found",
                    "message" => "Product not found. Please check the barcode or use manual entry."
                ]);
                log_action($conn, $_SESSION['user_id'], "Product not found", "Barcode: $barcode");
				exit;
            }
        } else {
            //show_modal($nonce, 'Ungültiges QRCode Format. -> manuelle Eingabe.', 'danger', 'Nicht Lesbar', 'codeNotReadableModal');
			echo json_encode([
                "status" => "invalid_format",
                "message" => "Invalid QR Code format. Please use manual entry."
            ]);
			exit;
        }
    } elseif (filter_input(INPUT_POST, 'unsell_product') !== null) {
        if (filter_input(INPUT_POST, 'product_id') !== null && is_numeric($_POST['product_id'])) {
			$product_id = (int) $_POST['product_id'];
	
			$stmt = $conn->prepare("UPDATE products SET sold=0 WHERE id=?");
			$stmt->bind_param("i", $product_id);
			if ($stmt->execute()) {
				log_action($conn, $_SESSION['user_id'], "Product marked as unsold", "Product ID: $product_id");
				echo json_encode(["status" => "success", "message" => "Product unsold successfully."]);
			} else {
				echo json_encode(["status" => "error", "message" => "Failed to mark product as unsold."]);
			}
		} else {
			echo json_encode(["status" => "error", "message" => "Invalid product ID."]);
		}
        log_action($conn, $_SESSION['user_id'], "Product marked as unsold", "Product ID: $product_id");

		exit;
    } elseif (filter_input(INPUT_POST, 'reset_sum') !== null) {
        array_unshift($groups, ['products' => [], 'sum' => 0]);
        $sum_of_prices = 0.0;
        $_SESSION['sum_of_prices'] = $sum_of_prices;

        $buyer_index++;
        $_SESSION['buyer_index'] = $buyer_index;

        log_action($conn, $_SESSION['user_id'], "Sum of prices reset");
    }
    $_SESSION['groups'] = $groups;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Kassierer</title>
    <!-- Preload and link CSS files -->
    <link rel="preload" href="css/bootstrap.min.css" as="style" id="bootstrap-css">
	<link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="preload" href="css/all.min.css" as="style" id="all-css">
    <link rel="preload" href="css/style.css" as="style" id="style-css">
    <noscript>
        <link href="css/bootstrap.min.css" rel="stylesheet">
        <link href="css/all.min.css" rel="stylesheet">
        <link href="css/style.css" rel="stylesheet">
    </noscript>
    <script nonce="<?php echo $nonce; ?>">
        document.getElementById('bootstrap-css').rel = 'stylesheet';
        document.getElementById('all-css').rel = 'stylesheet';
        document.getElementById('style-css').rel = 'stylesheet';
    </script>
    <script src="js/html5-qrcode.min.js" nonce="<?php echo $nonce; ?>"></script>
	<script src="js/utilities.js" nonce="<?php echo $nonce; ?>"></script>
	<script nonce="<?php echo $nonce; ?>">
	document.addEventListener('DOMContentLoaded', function () {
		const groupSumHeading = document.querySelector("h3.sumPrice");
		const manualBarcodeForm = document.getElementById("manual-entry-form-container");
		const modal = document.getElementById("change-calculator-modal");
		const amountReceivedInput = document.getElementById("amount-received");
		const calculatedChangeInput = document.getElementById("calculated-change");
		const confirmButton = document.getElementById("confirm-transaction");
		const scannedProductsTable = document.getElementById("scanned-products");
		const abschlussButton = document.querySelector('[name="reset_sum"]');
		const scannerContainer = document.getElementById("scanner-container");
		const toggleScannerButton = document.getElementById("toggle-scanner");
		const html5QrCode = new Html5Qrcode("scanner-container");
		const exactPaymentButton = document.getElementById("exact-payment");
		const beepAudio = new Audio("assets/beep.wav"); // Path to beep sound
		let currentGroup = JSON.parse(localStorage.getItem("currentGroup")) || [];
		let groups = JSON.parse(localStorage.getItem("groups")) || [];
		let scanCooldown = false;
		let lastScannedCode = null;
		let isScannerRunning = true;
		
		function stopScanner() {
			if (isScannerRunning) {
				html5QrCode.stop().then(() => {
					console.log("Scanner stopped.");
					scannerContainer.classList.add("d-none"); // Hide scanner
					toggleScannerButton.textContent = "Scanner starten";
					toggleScannerButton.classList.remove("btn-danger");
					toggleScannerButton.classList.add("btn-primary");
					isScannerRunning = false;
				}).catch((err) => {
					console.error("Error stopping scanner:", err);
				});
			}
		}

		function startScanner() {
			if (!isScannerRunning) {
				scannerContainer.classList.remove("d-none"); // Show scanner
				toggleScannerButton.textContent = "Scanner stoppen";
				toggleScannerButton.classList.remove("btn-primary");
				toggleScannerButton.classList.add("btn-danger");

				if (!html5QrCode.isScanning) {
					initializeScanner();
				}
				isScannerRunning = true;
			}
		}

		// Toggle scanner on button click
		toggleScannerButton.addEventListener('click', function () {
			if (isScannerRunning) {
				stopScanner();
			} else {
				startScanner();
			}
		});
		
		manualBarcodeForm.addEventListener("submit", function (event) {
			event.preventDefault(); // Prevent the default form submission

			// Collect form data
			const sellerNumber = document.getElementById("manual-seller-number").value;
			const productId = document.getElementById("manual-product-id").value;

			// Validate input (optional but recommended)
			if (!sellerNumber || !productId) {
				showToast("Fehler ❌", "Bitte alle Felder ausfüllen.", "danger", 2000);
				return;
			}

			// Prepare POST data
			const postData = new URLSearchParams();
			postData.append("isManualInput", "1");
			postData.append("manual-seller-number", sellerNumber);
			postData.append("manual-product-id", productId);

			// Send AJAX request
			fetch("cashier.php", {
				method: "POST",
				headers: {
					"Content-Type": "application/x-www-form-urlencoded",
				},
				body: postData.toString(),
			})
				.then(response => response.json())
				.then(data => {
					if (data.status === "success") {
						// Handle success (e.g., add product to current group)
						const product = {
							id: data.product.id,
							name: data.product.name,
							price: data.product.price,
							sellerNumber: data.product.sellerNumber,
						};
						currentGroup.push(product);
						renderCurrentGroup();
						updateGroupSumPreview();
						showToast("Erfolgreich ✔️", "Produkt erfolgreich hinzugefügt.", "success", 2000);
					} else {
						// Handle errors
						showToast("Fehler ❌", data.message, "danger", 2000);
					}
				})
				.catch(error => {
					console.error("Error processing manual input:", error);
					showToast("Fehler ❌", "Serverfehler. Bitte erneut versuchen.", "danger", 2000);
				});
		});
	
		function getQrBoxSize() {
			const minDimension = Math.min(window.innerWidth, window.innerHeight);
			const qrSize = Math.floor(minDimension * 0.6); // 60% of the smallest viewport dimension
			return Math.max(qrSize, 200); // Ensure a minimum size for small screens
		}

		function initializeScanner() {
			const qrBoxSize = {width: 125, height: 250};

			html5QrCode.start(
				{ facingMode: "environment" }, // Rear camera
				{
					fps: 3,
					qrbox: qrBoxSize,
					aspectRatio: 1,
				},
				(decodedText, decodedResult) => {
					if (!scanCooldown && decodedText !== lastScannedCode) {
						lastScannedCode = decodedText;

						// Play beep sound
						beepAudio.play();

						// Send scanned code to the server
						ajaxPost(decodedText);

						// Start cooldown
						scanCooldown = true;
						setTimeout(() => {
							scanCooldown = false;
							lastScannedCode = null; // Allow rescanning after cooldown
						}, 2000); // 2-second cooldown
					}
				},
				(errorMessage) => {
					console.warn(`QR code scanning error: ${errorMessage}`);
				}
			).catch((err) => {
				console.error("Error starting QR code scanner: ", err);
			});
		}

		function ajaxPost(barcode) {
			const xhr = new XMLHttpRequest();
			xhr.open("POST", "cashier.php", true); // PHP handler
			xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");

			xhr.onload = function () {
				if (xhr.status === 200) {
					try {
						const response = JSON.parse(xhr.responseText);
						switch (response.status) {
							case "success":
								console.log("Server response:", response);

								const product = {
									id: response.product.id, // Include product ID
									name: response.product.name,
									price: response.product.price,
									sellerNumber: response.product.sellerNumber,
								};

								// Add product to current group
								currentGroup.push(product);
								localStorage.setItem("currentGroup", JSON.stringify(currentGroup));

								// Update the table
								renderScannedProducts();
								updateGroupSumPreview();
								// Show a success toast
								showToast('Erfolgreich ✔️', `Produkt "${response.product.name}" erfolgreich gescannt.`, 'success', 2000);
								break;

							case "already_scanned":
								console.log("Product already scanned:", response);

								// Show a warning toast
								showToast('Warnung ⚠️', `Produkt "${response.product.name}" wurde bereits gescannt.`, 'warning', 2000);
								break;

							case "not_found":
								showToast('Fehler ❌', 'Produkt nicht gefunden. Bitte überprüfen Sie den Barcode.', 'danger', 2000);
								break;

							case "invalid_format":
								showToast('Fehler ❌', 'Ungültiges Barcode-Format. Bitte verwenden Sie die manuelle Eingabe.', 'danger', 2000);
								break;

							default:
								showToast('Fehler ❌', 'Unbekannter Fehler. Bitte erneut versuchen.', 'danger', 2000);
								break;
						}
					} catch (error) {
						console.error("Error parsing server response:", error);
					}
				} else {
					console.error("Failed to process the scanned code:", xhr.status, xhr.statusText);
				}
			};

			xhr.onerror = function () {
				console.error("Error during the AJAX request.");
			};

			// Send the barcode to the server
			xhr.send(`barcode=${encodeURIComponent(barcode)}`);
		}
		
		function renderGroup(group, index) {
			const groupSum = calculateSum(group.products).toFixed(2);
			return `
				<tr data-toggle="collapse" data-target="#group-${index}" class="clickable">
					<td colspan="4">
						Käufer ${groups.length - index} - Artikelanzahl: ${group.products.length} - Summe: €${groupSum}
					</td>
				</tr>
				<tr id="group-${index}" class="collapse">
					<td colspan="4">
						<table class="table">
							${group.products.map(renderProductRow).join("")}
						</table>
					</td>
				</tr>`;
		}

		function renderProductRow(product) {
			return `
				<tr>
					<td>${product.name}</td>
					<td>€${product.price.toFixed(2)}</td>
					<td>${product.sellerNumber}</td>
					<td></td>
				</tr>`;
		}

		function renderCurrentGroup() {
			// If the current group is empty, display a placeholder row
			if (currentGroup.length === 0) {
				return "";
			}

			// Generate the HTML for the current group rows
			return currentGroup.map((product, index) => `
				<tr>
					<td>${product.name}</td>
					<td>€${product.price.toFixed(2)}</td>
					<td>${product.sellerNumber}</td>
					<td>
						<button class="btn btn-sm btn-danger remove-btn" data-id="${product.id}" data-index="${index}">
							<i class="fas fa-trash-alt"></i> <!-- Font Awesome icon -->
						</button>
					</td>
				</tr>
			`).join("");
		}
	
		function renderScannedProducts() {
			const tableBody = scannedProductsTable.querySelector("tbody");
			tableBody.innerHTML = "";

			groups.forEach((group, index) => {
				tableBody.insertAdjacentHTML("beforeend", renderGroup(group, index));
			});

			tableBody.insertAdjacentHTML("beforeend", renderCurrentGroup());

			// If both groups and currentGroup are empty, display a placeholder
			if (groups.length === 0 && currentGroup.length === 0) {
				const emptyRow = "";
				tableBody.insertAdjacentHTML("beforeend", emptyRow);
			}
		}

		// Attach a single event listener to the table for better performance
		document.querySelector("#scanned-products tbody").addEventListener("click", function (event) {
			if (event.target.closest(".remove-btn")) {
				const button = event.target.closest(".remove-btn");
				const productId = parseInt(button.dataset.id, 10);
				const index = parseInt(button.dataset.index, 10);
				removeProduct(productId, index);
			}
		});
							
		function removeProduct(productId, index) {
			const productToRemove = currentGroup[index];
			if (!productToRemove || !productToRemove.id) {
				console.error("Invalid product data for removal.");
				return;
			}

			// Ensure the index is within bounds
			if (index < 0 || index >= currentGroup.length) {
				console.error("Invalid product index:", index);
				return;
			}

			// Remove product from currentGroup
			currentGroup.splice(index, 1);

			// Update localStorage before re-rendering
			localStorage.setItem("currentGroup", JSON.stringify(currentGroup));

			// Send AJAX request to unsell the product
			const xhr = new XMLHttpRequest();
			xhr.open("POST", "cashier.php", true); // PHP handler
			xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");

			xhr.onload = function () {
				if (xhr.status === 200) {
					try {
						const response = JSON.parse(xhr.responseText);
						if (response.status === "success") {
							console.log(`Product ID ${productToRemove.id} unsold successfully.`);
							currentGroup.splice(index, 1); // Remove the product from the group
							renderScannedProducts(); // Re-render the table
							
							updateGroupSumPreview();
							showToast("Erfolgreich ✔️", "erfolgreich Storniert.", "success", 2000);
						} else {
							showToast("Fehler ❌", "Produkt konnte nicht entfernt werden.", "danger", 2000);
						}
					} catch (error) {
						console.error("Error parsing server response:", error);
					}
				} else {
					console.error("Failed to remove product:", xhr.status, xhr.statusText);
					showToast("Fehler ❌", "Serverfehler beim Entfernen des Produkts.", "danger", 2000);
				}
			};

			xhr.onerror = function () {
				console.error("Error during the AJAX request.");
				showToast("Fehler ❌", "Netzwerkfehler beim Entfernen des Produkts.", "danger", 2000);
			};

			// Send the product ID to the server
			xhr.send(`unsell_product=1&product_id=${encodeURIComponent(productId)}`);

			// Update the table
			renderScannedProducts();
		}
		
		function forceQrBoxRedraw() {
			const qrBoxSize = getQrBoxSize();
			html5QrCode.applyVideoConstraints({
				width: qrBoxSize,
				height: qrBoxSize
			}).then(() => {
				console.log("QR box size updated.");
			}).catch(err => {
				console.error("Error updating QR box size:", err);
			});
		}

		function reinitializeScanner() {
			if (html5QrCode.isScanning) {
				html5QrCode.stop().then(() => {
					initializeScanner();
				}).catch(err => {
					console.error("Error stopping scanner:", err);
				});
			} else {
				initializeScanner();
			}
		}

		function calculateSum(products) {
			return products.reduce((sum, product) => sum + product.price, 0);
		}

		scannedProductsTable.addEventListener("click", function (e) {
			if (e.target.classList.contains("btn-danger")) {
				const rowIndex = e.target.dataset.index;
				currentGroup.splice(rowIndex, 1);
				renderScannedProducts();
			}
		});
	
		// Initialize scanner on page load
		initializeScanner();

		// Render existing scanned products on page load
		renderScannedProducts();

		// Listen for viewport changes
		window.addEventListener("resize", reinitializeScanner);

		
		function saveCurrentGroup() {
			if (currentGroup.length > 0) {
				// Add currentGroup to groups
				groups.unshift({ products: [...currentGroup] });
				localStorage.setItem("groups", JSON.stringify(groups));

				// Clear currentGroup and update localStorage
				currentGroup = [];
				localStorage.setItem("currentGroup", JSON.stringify(currentGroup));

				// Update the table
				renderScannedProducts();
			}
		}
		
		function updateGroupSumPreview() {
			const totalSum = calculateSum(currentGroup).toFixed(2);
			groupSumHeading.textContent = `Summe: €${totalSum}`;
		}

		abschlussButton.addEventListener("click", function (e) {
			e.preventDefault();
			const totalSum = calculateSum(currentGroup).toFixed(2);

			amountReceivedInput.value = "";
			calculatedChangeInput.value = "";

			modal.querySelector(".modal-body .form-group:first-child label").textContent = `Summe: €${totalSum}`;
			$(modal).modal("show");
		});
		
		document.getElementById("amount-received").addEventListener("input", function () {
			const totalSum = calculateSum(currentGroup).toFixed(2);
			const amountReceived = parseFloat(this.value) || 0;
			const change = amountReceived - totalSum;
			calculatedChangeInput.value = change >= 0 ? change.toFixed(2) : "Unzureichend";
		});

		confirmButton.addEventListener("click", function () {
			const amountReceived = parseFloat(amountReceivedInput.value);
			const totalSum = calculateSum(currentGroup).toFixed(2);

			if (isNaN(amountReceived) || amountReceived < totalSum) {
				calculatedChangeInput.classList.add("is-invalid");
				calculatedChangeInput.value = "Unzureichend";
				return;
			}

			calculatedChangeInput.classList.remove("is-invalid");
			saveCurrentGroup();
			updateGroupSumPreview();
			showToast("Erfolgreich ✔️", "Zahlung abgeschlossen. Neuer Käufer begonnen.", "success", 3000);
			$(modal).modal("hide");
		});

		// Event for "Passend gegeben" button
		exactPaymentButton.addEventListener("click", function () {
			const totalSum = calculateSum(currentGroup).toFixed(2);
			amountReceivedInput.value = totalSum;
			const amountReceived = parseFloat(totalSum);
			
			
			saveCurrentGroup();
			updateGroupSumPreview();
			showToast("Erfolgreich ✔️", "Zahlung abgeschlossen. Neuer Käufer begonnen.", "success", 3000);
			$(modal).modal("hide");
		});
	
		renderScannedProducts();
	});
    </script>
</head>
<body>
	<!-- Navbar -->
	<?php include 'navbar.php'; ?> <!-- Include the dynamic navbar -->
	
    <div class="container">
        <div class="scanner-wrapper">
            <div id="scanner-container">
                <div id="scanner-line"></div>
                <div class="overlay"></div>
            </div>
        </div>
        <div class="button-container text-center">
            <button id="toggle-scanner" class="btn btn-danger btn-full-width">Scanner stoppen</button>
        </div>
        <form id="scan-form" action="cashier.php?nocache=<?php echo time(); ?>" method="post">
            <input type="hidden" id="barcode" name="barcode">
        </form>
		<div class="manual-entry-container">
			<!-- Expander button -->
			<button class="btn btn-secondary btn-full-width mb-2 mt-2" type="button" data-toggle="collapse" data-target="#manual-entry-form-container" aria-expanded="false" aria-controls="manual-entry-form-container">
				Manuelle Eingabe
			</button>

			<!-- Collapsible manual entry form -->
			<div class="collapse" id="manual-entry-form-container">
				<div class="card card-body">
					<form id="manual-entry-form" action="cashier.php?nocache=<?php echo time(); ?>" method="post">
						<div class="form-group">
							<label for="manual-seller-number">Verkäufernummer (V):</label>
							<input type="number" class="form-control" id="manual-seller-number" name="seller_number" min="1" required>
						</div>
						<div class="form-group">
							<label for="manual-product-id">Artikelnummer (A):</label>
							<input type="number" class="form-control" id="manual-product-id" name="product_id" min="1" max="999" required>
						</div>
						<input type="hidden" name="isManualInput" value="1">
						<button type="submit" class="btn btn-primary btn-full-width">Artikel hinzufügen</button>
					</form>
				</div>
			</div>
		</div>

        <form action="cashier.php?nocache=<?php echo time(); ?>" method="post">
            <button type="submit" name="reset_sum" class="btn btn-success btn-full-width mb-2">Abschluss</button>
        </form>
        <h3 class="mt-2 sumPrice">Summe: €<?php echo number_format($sum_of_prices, 2, ',', '.'); ?></h3>
        <hr>
        <h3 class="mt-1">Erfolgreich gescannte Artikel</h3>
		<div class="table-container">
            <table id="scanned-products" class="table table-bordered mt-3">
                <thead>
                    <tr>
                        <th>Artikelname</th>
                        <th>Preis (€)</th>
                        <th>Verkäufer-Nr.</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Rows will be dynamically inserted here -->
                </tbody>
            </table>
        </div>
    </div>
	<div id="change-calculator-modal" class="modal" tabindex="-1" role="dialog">
		<div class="modal-dialog" role="document">
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title">Berechnung des Wechselgeldes</h5>
					<button type="button" class="close" data-dismiss="modal" aria-label="Close">
						<span aria-hidden="true">&times;</span>
					</button>
				</div>
				<div class="modal-body">
					<form id="calculator-form">
						<div class="form-group">
							<label for="amount-received">Erhaltene Summe (€):</label>
							<input type="number" class="form-control" id="amount-received" placeholder="Betrag eingeben">
						</div>
						<div class="form-group">
							<label for="calculated-change">Wechselgeld (€):</label>
							<input type="text" class="form-control" id="calculated-change" readonly>
						</div>
					</form>
				</div>
				<div class="modal-footer">
					<div class="row mx-auto">
							<button type="button" class="btn btn-success w-100 m-3 mb-5" id="confirm-transaction">Summe</button>
						<div class="col-md-6 col-sm-12 mb-3">
							<button type="button" class="btn btn-primary w-100" id="exact-payment">Passend gegeben</button>
						</div>
						<div class="col-md-6 col-sm-12 mb-3">
							<button type="button" class="btn btn-secondary w-100" data-dismiss="modal">Abbrechen</button>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
    <?php if (false): ?>
        <footer class="p-2 bg-light text-center fixed-bottom">
            <div class="row justify-content-center">
                <div class="col-lg-6 col-md-12">
                    <p class="m-0">
                        <?php echo process_footer_content(FOOTER); ?>
                    </p>
                </div>
            </div>
        </footer>
    <?php endif; ?>

	<div id="toast-container" class="toast-container position-fixed bottom-0 end-0 p-3">
		<!-- Toasts will be dynamically added here -->
	</div>

    <script src="js/jquery-3.7.1.min.js" nonce="<?php echo $nonce; ?>"></script>
    <script src="js/popper.min.js" nonce="<?php echo $nonce; ?>"></script>
    <script src="js/bootstrap.min.js" nonce="<?php echo $nonce; ?>"></script>

    <script nonce="<?php echo $nonce; ?>">
        $(document).ready(function() {
            function toggleBackToTopButton() {
                const scrollTop = $(window).scrollTop();

                if (scrollTop > 100) {
                    $('#back-to-top').fadeIn();
                } else {
                    $('#back-to-top').fadeOut();
                }
            }

            toggleBackToTopButton();

            $(window).scroll(function() {
                toggleBackToTopButton();
            });

            $('#back-to-top').click(function() {
                $('html, body').animate({ scrollTop: 0 }, 600);
                return false;
            });
		});
    </script>
	<script nonce="<?php echo $nonce; ?>">
		// Show the HTML element once the DOM is fully loaded
		document.addEventListener("DOMContentLoaded", function () {
			document.documentElement.style.visibility = "visible";
		});
	</script>
</body>
</html>
