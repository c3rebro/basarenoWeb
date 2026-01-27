# Copilot Instructions for BasarenoWeb

## Project Overview
BasarenoWeb is a PHP-based web application for managing number-based bazaars (Abgabebasar). It streamlines seller registration, product labeling (with QR codes), sales, and accounting for event organizers and sellers.

## Architecture & Key Components
- **Monolithic PHP app**: Each major function is a separate PHP file (e.g., seller_dashboard.php, admin_manage_sellers.php).
- **Role-based access**: User roles (Seller, Assistant, Cashier, Admin) determine access to features. Role logic is enforced in each relevant PHP file.
- **No framework**: The codebase is plain PHP, using procedural and some modular patterns (see utilities.php for shared logic).
- **Data flow**: Most files interact directly with the MySQL database (see config.php.template for connection setup). Data is passed via POST/GET and PHP sessions.
- **Front-end**: Uses Bootstrap (see css/ and js/), with some custom JS for QR/barcode and PDF generation (see js/).

## Developer Workflows
- **Setup**: Copy files to web root, configure config.php (see config.php.template), and run first_time_setup.php for initial DB setup.
- **No build step**: PHP is interpreted directly. No transpilation or asset build required.
- **Testing**: No automated tests present. Manual testing via browser is standard.
- **Debugging**: Use browser dev tools and PHP error logs. Enable error reporting in config.php for development.

## Project-Specific Conventions
- **File-per-feature**: Each major workflow (login, seller management, checkout, etc.) is a separate PHP file.
- **Minimal OOP**: Most logic is procedural. Shared functions are in utilities.php.
- **German/English mix**: UI and code comments may be in German or English. Variable names are often descriptive in German.
- **Direct DB access**: SQL queries are written inline in PHP files. No ORM is used.
- **Session-based auth**: User state is tracked via PHP sessions.

## Integration & External Dependencies
- **Bootstrap**: For UI (see css/ and js/ directories).
- **QR/Barcode**: Uses js/qrcode.min.js and js/html5-qrcode.min.js for label generation and scanning.
- **PDF export**: Uses js/jspdf.*.js and js/html2canvas.min.js for receipts and labels.
- **Joomla integration**: Optional, via a module for embedding registration (see README.md).

## Key Files & Directories
- `config.php.template`: DB and mail config (copy to config.php for use)
- `utilities.php`: Shared PHP functions
- `seller_dashboard.php`, `admin_manage_sellers.php`, etc.: Main workflows
- `js/`, `css/`: Front-end assets
- `README.md`: High-level documentation and feature overview

## Example Patterns
- To add a new workflow, create a new PHP file following the structure of existing ones (e.g., seller_products.php).
- For shared logic, add to utilities.php and include as needed.
- For new roles or permissions, update role checks in each relevant PHP file.

---
For more details, see README.md or the comments in each main PHP file.
