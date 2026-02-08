<?php
// config.defaults.php
// Fallback values to keep constants defined when config.php is missing.
// These are only applied if the constant isn't already defined.

if (!defined('DEBUG')) {
    define('DEBUG', false);
}

if (!defined('LANGUAGE')) {
    define('LANGUAGE', '');
}

if (!defined('DB_INITIALIZED')) {
    define('DB_INITIALIZED', false);
}

if (!defined('BASE_URI')) {
    define('BASE_URI', '');
}

if (!defined('DB_SERVER')) {
    define('DB_SERVER', '');
}

if (!defined('DB_USERNAME')) {
    define('DB_USERNAME', '');
}

if (!defined('DB_PASSWORD')) {
    define('DB_PASSWORD', '');
}

if (!defined('DB_NAME')) {
    define('DB_NAME', '');
}

if (!defined('SECRET')) {
    define('SECRET', '');
}

if (!defined('SMTP_FROM')) {
    define('SMTP_FROM', '');
}

if (!defined('SMTP_FROM_NAME')) {
    define('SMTP_FROM_NAME', '');
}

if (!defined('FOOTER')) {
    define('FOOTER', '');
}
