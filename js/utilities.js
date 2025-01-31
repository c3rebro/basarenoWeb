function showToast(title, message, type = 'info', duration = 3000) {
	const toastContainer = document.getElementById('toast-container');
	const toastId = `toast-${Date.now()}`;
	const bgClass = {
		success: 'bg-success',
		info: 'bg-info',
		warning: 'bg-warning',
		danger: 'bg-danger',
	}[type] || 'bg-info';

	// Ensure the container is visible when adding a toast
    toastContainer.style.display = 'block';
	
	// Create toast element
	const toastElement = document.createElement('div');
	toastElement.className = `toast ${bgClass} text-white border-0`;
	toastElement.setAttribute('role', 'alert');
	toastElement.setAttribute('aria-live', 'assertive');
	toastElement.setAttribute('aria-atomic', 'true');
	toastElement.id = toastId;

	// Toast inner HTML
	toastElement.innerHTML = `
		<div class="toast-header ${bgClass} text-white">
			<strong class="me-auto">${title}</strong>
			<button type="button" class="close text-white ml-auto" data-dismiss="toast" aria-label="Schliessen">
				<span aria-hidden="true">&times;</span>
			</button>
		</div>
		<div class="toast-body">
			${message}
		</div>
	`;

	// Append toast to container
	toastContainer.appendChild(toastElement);

	// Initialize the toast (Bootstrap 4.6.2 JS)
	$(toastElement).toast({ delay: duration });
	$(toastElement).toast('show');

	// Remove toast after hidden event
    $(toastElement).on('hidden.bs.toast', function () {
        toastElement.remove();
        
        // Check if the container is empty and hide it
        if (toastContainer.children.length === 0) {
            toastContainer.style.display = 'none';
        }
    });
	
	
}

/**
 * Refreshes the product table for a given seller.
 * @param {string} sellerNumber - The seller number for which to fetch products.
 * @returns {Promise} - Resolves when the table is successfully updated, rejects on error.
 */
function refreshProductTable(sellerNumber) {
    return fetch(`fetch_products.php?seller_number=${sellerNumber}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const tableBody = document.querySelector('#productTable tbody');
                tableBody.innerHTML = ''; // Clear current table rows

                // Filter the products to include only those where in_stock = 0
                const productsForSale = data.data.filter(product => product.in_stock === 0);

                if (productsForSale.length > 0) {
                    productsForSale.forEach(product => {
                        const formattedPrice = parseFloat(product.price).toFixed(2).replace('.', ',') + ' €';
                        const row = `
                            <tr>
                                <td>${product.name}</td>
                                <td>${product.size}</td>
                                <td>${formattedPrice}</td>
                                <td class="text-center p-2">
                                    <select class="form-control action-dropdown" data-product-id="${product.id}">
                                        <option value="">Aktion wählen</option>
                                        <option value="edit">Bearbeiten</option>
                                        <option value="stock">Ins Lager legen</option>
                                        <option value="delete">Löschen</option>
                                    </select>
                                    <button class="btn btn-primary btn-sm execute-action" data-product-id="${product.id}">Ausführen</button>
                                </td>
                            </tr>`;
                        tableBody.insertAdjacentHTML('beforeend', row);
                    });
                } else {
                    tableBody.innerHTML = `<tr><td colspan="5">Keine Artikel gefunden.</td></tr>`;
                }

                // Resolve the Promise to indicate success
                return true;
            } else {
                // Reject the Promise with an error message
                throw new Error('Fehler beim Aktualisieren der Tabelle.');
            }
        })
        .catch(error => {
            console.error('Error fetching table data:', error);
            throw error; // Re-throw the error for the caller to handle
        });
}

/**
 * Refreshes the stock products table and updates the stock product count in the card header.
 * @returns {Promise} Resolves when the table is updated.
 */
function refreshStockTable() {
    return fetch(`fetch_products.php?seller_number=${sellerNumber}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const tableBody = document.querySelector('#collapseStock table tbody');
                tableBody.innerHTML = ''; // Clear current table rows

                // Filter the products to include only those in stock
                const stockProducts = Array.isArray(data.data) ? data.data.filter(product => product.in_stock === 1) : [];

                if (stockProducts.length > 0) {
                    stockProducts.forEach(product => {
                        const formattedPrice = parseFloat(product.price).toFixed(2).replace('.', ',') + ' €';
                        const row = `
                            <tr>
                                <td>
                                    <input type="checkbox" class="bulk-select-stock" name="product_ids[]" value="${product.id}">
                                </td>
                                <td>${product.name}</td>
                                <td>${product.size}</td>
                                <td>${formattedPrice}</td>
                            </tr>`;
                        tableBody.insertAdjacentHTML('beforeend', row);
                    });
                } else {
                    tableBody.innerHTML = `<tr><td colspan="4">Keine Produkte im Lager.</td></tr>`;
                }

                // Update the stock products count dynamically
                document.querySelector('.card-header-stock-products').textContent = `Deine Artikel im Lager (Anzahl: ${stockProducts.length})`;

                return true;
            } else {
                throw new Error('Fehler beim Aktualisieren der Lagertabelle.');
            }
        })
        .catch(error => {
            console.error('Error fetching stock products:', error);
            throw error;
        });
}

function refreshSellerData() {
    fetch('fetch_seller_data.php')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const sellerDropdown = document.getElementById('sellerNumberSelect');
                sellerDropdown.innerHTML = ''; // Clear existing options
                
                if (data.data.length === 0) {
                    // No seller numbers available
                    const defaultOption = document.createElement('option');
                    defaultOption.value = '';
                    defaultOption.disabled = true;
                    defaultOption.selected = true;
                    defaultOption.textContent = 'Nicht verfügbar';
                    sellerDropdown.appendChild(defaultOption);
                } else {
                    // Populate seller numbers
                    data.data.forEach((seller, index) => {
                        const option = document.createElement('option');
                        option.value = seller.seller_number;
                        option.textContent = `Verkäufernummer: ${seller.seller_number} (${seller.seller_verified ? 'frei geschalten' : 'Nicht frei geschalten'})`;
                        if (index === 0) option.selected = true;
                        sellerDropdown.appendChild(option);
                    });
                }
                
                // Update seller sections
                updatePerSellerOverview(data.data); // Per seller overview
            } else {
                console.error('Error fetching seller data:', data.message);
            }
        })
        .catch(error => {
            console.error('Error during fetchSellerData:', error);
        });
}

function updatePerSellerOverview() {
    const overviewContainer = document.getElementById('perSellerNumberOverview');
    overviewContainer.innerHTML = ''; // Clear existing content

    if (sellers.length === 0) {
        overviewContainer.innerHTML = '<p>Keine Verkäufernummern vorhanden.</p>';
        return;
    }

    sellers.forEach((seller) => {
        // Filter products for this seller
        const sellerProducts = products.filter(p => p.seller_number === seller.seller_number);

        // Calculate sold product count and total payout
        const brokerage = upcomingBazaar.brokerage ?? 0; // Ensure brokerage is not undefined
        let soldProductCount = 0;
        let totalPayout = 0;

        // Get only products that were actually sold
        const soldProductsHtml = sellerProducts
            .filter(product => product.in_stock === 0 && product.sold === 1) // Sold products only
            .map(product => {
                soldProductCount++;
                totalPayout += parseFloat(product.price) * (1 - brokerage); // Deduct brokerage per product
                
                return `
                    <tr>
                        <td>${product.name}</td>
                        <td>${product.size}</td>
                        <td>${parseFloat(product.price).toFixed(2).replace('.', ',')} €</td>
                    </tr>`;
            })
            .join('');

		// Get only unsold products
        const unsoldProductsHtml = sellerProducts
            .filter(product => product.in_stock === 0 && product.sold === 0) // Unsold products only
            .map(product => {
                return `
                    <tr>
                        <td>${product.name}</td>
                        <td>${product.size}</td>
                        <td>${parseFloat(product.price).toFixed(2).replace('.', ',')} €</td>
                    </tr>`;
            })
            .join('');
			
        const activeProductsCount = sellerProducts.length;

        const card = `
            <div class="card mb-4">
                <div class="card-header">
                    VerkäuferNr.: ${seller.seller_number}
                </div>
                <div class="card-body">
                    <p>Status: ${seller.seller_verified ? 'Verifiziert' : 'Nicht verifiziert'}</p>
                    <p>Anzahl der Produkte (gesamt): ${activeProductsCount}</p>
                    <p>Davon verkauft: ${soldProductCount}</p>
                    <p>Gesamtauszahlung: ${totalPayout.toFixed(2).replace('.', ',')} €</p>
                    
					<div class="row">
						<div class="col-md-6 col-sm-12 mt-3">
							<button class="btn btn-success w-100 mt-2" data-toggle="collapse" data-target="#products-sold-${seller.seller_number}">
								Verkaufte Produkte anzeigen
							</button>
						</div>
						<div class="col-md-6 col-sm-12 mt-3">
							<button class="btn btn-warning w-100 mt-2" data-toggle="collapse" data-target="#products-unsold-${seller.seller_number}">
								Nicht verkaufte Produkte anzeigen
							</button>
						</div>						
					</div>
					<!-- Sold Products Button -->

                    <div id="products-sold-${seller.seller_number}" class="collapse mt-3 table-responsive-sm">
                        <table class="table table-bordered table-striped w-100">
                            <thead>
                                <tr>
                                    <th>Produktname</th>
                                    <th>Größe</th>
                                    <th>Preis (€)</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${soldProductsHtml || '<tr><td colspan="3">Keine verkauften Produkte.</td></tr>'}
                            </tbody>
                        </table>
                    </div>

                    <!-- Unsold Products Button -->

                    <div id="products-unsold-${seller.seller_number}" class="collapse mt-3 table-responsive-sm">
                        <table class="table table-bordered table-striped w-100">
                            <thead>
                                <tr>
                                    <th>Produktname</th>
                                    <th>Größe</th>
                                    <th>Preis (€)</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${unsoldProductsHtml || '<tr><td colspan="3">Keine nicht verkauften Produkte.</td></tr>'}
                            </tbody>
                        </table>
                    </div>
					
                </div>
            </div>
        `;
        overviewContainer.insertAdjacentHTML('beforeend', card);
    });
}


function updateRequestSellerSection(bazaar) {
    const requestForm = document.getElementById('requestSellerNumberForm');
    const requestButton = document.querySelector('#requestSellerNumberForm button[type="submit"]');
    const actionDropdown = document.querySelector('select[name="action"]');
    const infoMessage = document.getElementById('sellerRequestInfoMessage');

    // Check if the registration is open
    const now = new Date();
    const startReqDate = new Date(bazaar.startReqDate);
    const startDate = new Date(bazaar.startDate);

    if (now >= startReqDate && now < startDate) {
        // Registration is open
        requestForm.classList.remove('hidden'); // Show form
        requestButton.disabled = false; // Enable button
        actionDropdown.querySelector('option[value="validate"]').disabled = false; // Enable validate
        infoMessage.classList.add('hidden'); // Hide info message
    } else {
        // Registration closed
        requestForm.classList.add('hidden'); // Hide form
        requestButton.disabled = true; // Disable button
        actionDropdown.querySelector('option[value="validate"]').disabled = true; // Disable validate
        infoMessage.classList.remove('hidden'); // Show info message
    }
}