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
                const productsForSale = data.data.filter(product => Number(product.in_stock) === 0);

                const saleHeaderColumnCount = document.querySelectorAll('#productTable thead th').length || 5;
                const saleTotalLabelColspan = Math.max(saleHeaderColumnCount - 2, 1);

                let totalPrice = 0; // Initialize total price

                if (productsForSale.length > 0) {
                    productsForSale.forEach(product => {
                        const formattedPrice = parseFloat(esc(product.price)).toFixed(2).replace('.', ',') + ' €';
                        totalPrice += parseFloat(esc(product.price)); // Accumulate total price

                        const row = `
                            <tr class="product-row">
                                <td>
                                    <input type="checkbox" class="bulk-select-sale" value="${product.id}">
                                </td>
                                <td>${esc(product.name)}</td>
                                <td>${esc(product.size)}</td>
                                <td class="product-price" data-price="${esc(product.price)}">${formattedPrice}</td>
                                <td class="text-center p-2">
                                    <select class="form-control action-dropdown" data-product-id="${product.id}">
                                        <option value="">Aktion wählen</option>
                                        <option value="edit">Bearbeiten</option>
                                        <option value="stock">Ins Lager verschieben</option>
                                        <option value="delete">Löschen</option>
                                    </select>
                                    <button class="btn btn-primary btn-sm execute-action" data-product-id="${product.id}">Ausführen</button>
                                </td>
                            </tr>`;
                        tableBody.insertAdjacentHTML('beforeend', row);
                    });

                    // Append the total price row at the bottom
                    const totalRow = `
                        <tr class="table-info total-price-row">
                            <td colspan="${saleTotalLabelColspan}"><strong>Gesamtpreis:</strong></td>
                            <td id="totalPrice" data-total="${totalPrice}"><strong>${totalPrice.toFixed(2).replace('.', ',')} €</strong></td>
                            <td></td>
                        </tr>`;
                    tableBody.insertAdjacentHTML('beforeend', totalRow);
                } else {
                    tableBody.innerHTML = `<tr><td colspan="${saleHeaderColumnCount}">Keine Artikel gefunden.</td></tr>`;
                }

                // ✅ Fix: Update the header count WITHOUT the total row
                const activeProductCount = document.querySelectorAll('#productTable tbody .product-row').length;
                document.querySelector('.card-header-sale-products').textContent = `Deine aktiven Artikel (Anzahl: ${activeProductCount})`;
                document.querySelector('.card-header-remaining').textContent = `Neuen Artikel erstellen (max. ${maxProdPerSellers} noch: ${maxProdPerSellers - activeProductCount} möglich)`;
                
                return true; // Resolve promise on success
            } else {
                throw new Error('Fehler beim Aktualisieren der Tabelle.');
            }
        })
        .catch(error => {
            console.error('Error fetching table data:', error);
            throw error; // Re-throw error for caller to handle
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
                const stockProducts = Array.isArray(data.data) ? data.data.filter(product => Number(product.in_stock) === 1) : [];

                const stockHeaderColumnCount = document.querySelectorAll('#stockTable thead th').length || 4;

                if (stockProducts.length > 0) {
                    stockProducts.forEach(product => {
                        const formattedPrice = parseFloat(esc(product.price)).toFixed(2).replace('.', ',') + ' €';
                        const row = `
                            <tr>
                                <td>
                                    <input type="checkbox" class="bulk-select-stock" name="product_ids[]" value="${product.id}">
                                </td>
                                <td>${esc(product.name)}</td>
                                <td>${esc(product.size)}</td>
                                <td>${formattedPrice}</td>
                            </tr>`;
                        tableBody.insertAdjacentHTML('beforeend', row);
                    });
                } else {
                    tableBody.innerHTML = `<tr><td colspan="${stockHeaderColumnCount}">Keine Artikel im Lager.</td></tr>`;
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

// XSS mitigation: escape user-supplied text for HTML insertion
function esc(s){
  if (s === undefined || s === null) return '';
  return String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function refreshSellerData() {
    return fetch('fetch_seller_data.php')
    .then(response => response.json())
    .then (data => {
        if (data.success) {
            const sellerDropdown = document.getElementById('sellerNumberSelect');
            sellerDropdown.innerHTML = ''; // Clear existing options
            
            if (data.data.length === 0) {
                const defaultOption = document.createElement('option');
                defaultOption.value = '';
                defaultOption.disabled = true;
                defaultOption.selected = true;
                defaultOption.textContent = 'Nicht verfügbar';
                sellerDropdown.appendChild(defaultOption);
            } else {
                data.data.forEach((seller, index) => {
                    const option = document.createElement('option');
                    option.value = seller.seller_number;
                    option.textContent = `Verkäufernummer: ${seller.seller_number} (${seller.seller_verified ? 'frei geschalten' : 'Nicht frei geschalten'})`;
                    if (index === 0) option.selected = true;
                    sellerDropdown.appendChild(option);
                });
            }
            
            // Update seller sections
            updatePerSellerOverview(data.data); // ✅ Ensure this still works
            
            return data; // ✅ Ensures `refreshSellerData()` returns a Promise
        } else {
            console.error('Error fetching seller data:', data.message);
            throw new Error(data.message); // ✅ Ensure errors are properly thrown
        }
    }) .catch (error => {
        console.error('Error during fetchSellerData:', error);
        throw error; // ✅ Ensure calling code can catch the error
    });
}


function updatePerSellerOverview() {
    const overviewContainer = document.getElementById('perSellerNumberOverview');
    overviewContainer.innerHTML = ''; // Clear existing content

    if (sellers.length === 0) {
        overviewContainer.innerHTML = `
			<div class="card mb-4">
				<div class="card-header">
					An dieser Stelle kannst Du später den Status deiner verkauften Artikel einsehen.
				</div>
				<div class="card-body">
					Es gibt noch keine verkauften Artikel.
				</div>
			</div>`;
        return;
    }

    sellers.forEach((seller) => {
        // Filter products for this seller
        const sellerProducts = products.filter(p => p.seller_number === seller.seller_number);

        // Calculate sold product count and total payout
        const commission = (typeof upcomingBazaar.commission !== "undefined" && upcomingBazaar.commission !== null)
            ? upcomingBazaar.commission 
            : 0;
        // Ensure commission is not undefined
        let soldProductCount = 0;
        let totalPayout = 0;

        // Get only products that were actually sold
        const soldProductsHtml = sellerProducts
            .filter(product => product.in_stock === 0 && product.sold === 1) // Sold products only
            .map(product => {
                soldProductCount++;
                totalPayout += parseFloat(esc(product.price)) * (1 - commission); // Deduct commission per product
                
                return `
                    <tr>
                        <td>${esc(product.name)}</td>
                        <td>${esc(product.size)}</td>
                        <td>${parseFloat(esc(product.price)).toFixed(2).replace('.', ',')} €</td>
                    </tr>`;
            })
            .join('');

		// Get only unsold products
        const unsoldProductsHtml = sellerProducts
            .filter(product => product.in_stock === 0 && product.sold === 0) // Unsold products only
            .map(product => {
                return `
                    <tr>
                        <td>${esc(product.name)}</td>
                        <td>${esc(product.size)}</td>
                        <td>${parseFloat(esc(product.price)).toFixed(2).replace('.', ',')} €</td>
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
                    <p>Anzahl der Artikel (gesamt): ${activeProductsCount}</p>
                    <p>Davon verkauft: ${soldProductCount}</p>
                    <p>Gesamtauszahlung: ${totalPayout.toFixed(2).replace('.', ',')} €</p>
                    
					<div class="row">
						<div class="col-md-6 col-sm-12 mt-3">
							<button class="btn btn-success w-100 mt-2" data-toggle="collapse" data-target="#products-sold-${seller.seller_number}">
								Verkaufte Artikel anzeigen
							</button>
						</div>
						<div class="col-md-6 col-sm-12 mt-3">
							<button class="btn btn-warning w-100 mt-2" data-toggle="collapse" data-target="#products-unsold-${seller.seller_number}">
								Nicht verkaufte Artikel anzeigen
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
                                ${soldProductsHtml || '<tr><td colspan="3">Keine verkauften Artikel.</td></tr>'}
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
                                ${unsoldProductsHtml || '<tr><td colspan="3">Keine nicht verkauften Artikel.</td></tr>'}
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
    const start_req_date = new Date(bazaar.start_req_date);
    const start_date = new Date(bazaar.start_date);

    if (now >= start_req_date && now < start_date) {
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

function setCookie(name, value, days) {
    let expires = "";
    if (days) {
        let date = new Date();
        date.setTime(date.getTime() + days * 24 * 60 * 60 * 1000);
        expires = "; expires=" + date.toUTCString();
    }
    document.cookie = name + "=" + value + expires + "; path=/";
}

function getCookie(name) {
    let nameEQ = name + "=";
    let ca = document.cookie.split(';');
    for (let i = 0; i < ca.length; i++) {
        let c = ca[i];
        while (c.charAt(0) === ' ') c = c.substring(1, c.length);
        if (c.indexOf(nameEQ) === 0) return c.substring(nameEQ.length, c.length);
    }
    return null;
}

function removeCookie(name) {
    document.cookie = name + "=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
}