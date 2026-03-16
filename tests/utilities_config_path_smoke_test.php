<?php

declare(strict_types=1);

/**
 * Regression checks for config path handling in utilities.php.
 *
 * The archive cron script can run from arbitrary working directories,
 * so config lookups must be rooted at the utilities.php directory.
 */

$utilitiesPath = __DIR__ . '/../utilities.php';
$utilities = file_get_contents($utilitiesPath);

if ($utilities === false) {
    fwrite(STDERR, "Could not read {$utilitiesPath}\n");
    exit(1);
}

$checks = [
    'config path helper uses __DIR__' => "function get_config_path()",
    'config file resolved with __DIR__' => "return __DIR__ . '/config.php';",
    'default config file resolved with __DIR__' => "return __DIR__ . '/config.defaults.php';",
    'load_config requires helper path for config' => "require_once get_config_path();",
    'load_config requires helper path for defaults' => "require_once get_default_config_path();",
    'db connection uses utf8mb4 charset' => '$conn->set_charset("utf8mb4");',
];

$failures = [];
foreach ($checks as $label => $needle) {
    if (strpos($utilities, $needle) === false) {
        $failures[] = $label;
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Utilities path smoke checks failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "utilities config-path smoke checks passed.\n";
