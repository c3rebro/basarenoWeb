<?php
$role = $_SESSION['role'] ?? 'guest'; // Default to 'guest' if no role is set
$loggedIn = $_SESSION['loggedin'] ?? false;
$username = $_SESSION['username'] ?? '';

$currentPage = basename($_SERVER['PHP_SELF']);
?>

<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-light bg-light">
    <a class="navbar-brand" href="#">Basareno<i>Web</i></a>
    <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav">
        <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="navbarNav">
        <ul class="navbar-nav">
            <!-- Guest & General Items -->
            <li class="nav-item <?= ($currentPage == 'index.php') ? 'active' : '' ?>">
                <a class="nav-link" href="index.php">Startseite</a>
            </li>

            <?php if ($loggedIn): ?>
                <li class="nav-item <?= ($currentPage == 'seller_dashboard.php') ? 'active' : '' ?>">
                    <a class="nav-link" href="seller_dashboard.php">Dashboard</a>
                </li>
                <li class="nav-item <?= ($currentPage == 'seller_products.php') ? 'active' : '' ?>">
                    <a class="nav-link" href="seller_products.php">Meine Artikel</a>
                </li>
                <li class="nav-item <?= ($currentPage == 'seller_edit.php') ? 'active' : '' ?>">
                    <a class="nav-link" href="seller_edit.php">Mein Benutzerkonto</a>
                </li>
            <?php endif; ?>

            <?php if (in_array($role, ['cashier', 'admin'])): ?>
                <li class="nav-item <?= ($currentPage == 'cashier.php') ? 'active' : '' ?>">
                    <a class="nav-link" href="cashier.php">Kasse</a>
                </li>
            <?php endif; ?>

            <?php if (in_array($role, ['assistant', 'cashier', 'admin'])): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?= ($currentPage == 'acceptance.php' || $currentPage == 'pickup.php') ? 'active' : '' ?>"
                       href="#" id="assistantDropdown" role="button" data-toggle="dropdown">
                        Assistenz
                    </a>
                    <div class="dropdown-menu">
                        <a class="dropdown-item <?= ($currentPage == 'acceptance.php') ? 'active' : '' ?>" href="acceptance.php">Korbannahme</a>
                        <a class="dropdown-item <?= ($currentPage == 'pickup.php') ? 'active' : '' ?>" href="pickup.php">Korbrückgabe</a>
                    </div>
                </li>
            <?php endif; ?>
			
            <?php if ($role === 'admin'): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?= in_array($currentPage, ['admin_dashboard.php', 'admin_manage_users.php', 'admin_manage_bazaar.php', 'admin_manage_sellers.php', 'system_settings.php', 'system_log.php']) ? 'active' : '' ?>"
                       href="#" id="adminDropdown" role="button" data-toggle="dropdown">
                        Management
                    </a>
                    <div class="dropdown-menu">
                        <a class="dropdown-item <?= ($currentPage == 'admin_dashboard.php') ? 'active' : '' ?>" href="admin_dashboard.php">Admin Dashboard</a>
                        <a class="dropdown-item <?= ($currentPage == 'admin_manage_users.php') ? 'active' : '' ?>" href="admin_manage_users.php">Benutzer verwalten</a>
                        <a class="dropdown-item <?= ($currentPage == 'admin_manage_bazaar.php') ? 'active' : '' ?>" href="admin_manage_bazaar.php">Bazaar verwalten</a>
                        <a class="dropdown-item <?= ($currentPage == 'admin_manage_sellers.php') ? 'active' : '' ?>" href="admin_manage_sellers.php">Verkäufer verwalten</a>
                        <a class="dropdown-item <?= ($currentPage == 'system_settings.php') ? 'active' : '' ?>" href="system_settings.php">Systemeinstellungen</a>
                        <a class="dropdown-item <?= ($currentPage == 'system_log.php') ? 'active' : '' ?>" href="system_log.php">Protokolle</a>
                    </div>
                </li>
            <?php endif; ?>
        </ul>

        <!-- Right-aligned User Authentication -->
        <ul class="navbar-nav">
            <?php if ($loggedIn): ?>
				<li class="nav-item ml-auto"></li>
                <li class="nav-item">
                    <a class="nav-link navbar-user" href="seller_edit.php">
                        <i class="fas fa-user"></i> <?php echo htmlspecialchars($username); ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link btn btn-danger text-white p-2" href="logout.php">Abmelden</a>
                </li>
            <?php else: ?>
                <li class="nav-item ml-lg-auto">
                    <a class="nav-link btn btn-primary text-white p-2" href="login.php">Anmelden</a>
                </li>
            <?php endif; ?>
        </ul>
    </div>
</nav>
