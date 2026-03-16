<?php

declare(strict_types=1);

/**
 * Lightweight regression checks for archive_sold_products.php.
 *
 * We validate key safety guards by scanning the script source because the
 * production script executes database work on include.
 */

$scriptPath = __DIR__ . '/../archive_sold_products.php';
$script = file_get_contents($scriptPath);

if ($script === false) {
    fwrite(STDERR, "Could not read {$scriptPath}\n");
    exit(1);
}

$checks = [
    'uses __DIR__ for utilities include' => "require_once __DIR__ . '/utilities.php';",
    'guards against null/invalid mysqli connection' => "if (!\$conn instanceof mysqli)",
    'includes diagnostics in db connection failure' => "build_db_connection_error_message()",
    'ensures archive table charset before archiving' => "ensure_archive_products_charset(\$conn);",
    'migrates archive table charset to utf8mb4' => "ALTER TABLE archive_products CONVERT TO CHARACTER SET utf8mb4",
    'starts transaction inside try block after connection validation' => "// Start a transaction only after validating the connection",
];

$failures = [];
foreach ($checks as $label => $needle) {
    if (strpos($script, $needle) === false) {
        $failures[] = $label;
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Regression checks failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "archive_sold_products smoke checks passed.\n";
