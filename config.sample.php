<?php
// Copy to config.php. In production place ABOVE public_html and point
// _bootstrap.php's CONFIG_PATH at it. NEVER commit the real config.php.
return [
    // PDO DSN. Local XAMPP example below; production uses the cPanel DB.
    'dsn'      => 'mysql:host=127.0.0.1;dbname=nexus;charset=utf8mb4',
    'db_user'  => 'root',
    'db_pass'  => '',
    // bcrypt hash of the shared admin password.
    // Generate: php -r "echo password_hash('YOUR_PASSWORD', PASSWORD_BCRYPT), PHP_EOL;"
    'admin_hash' => '$2y$10$REPLACE_WITH_A_REAL_BCRYPT_HASH................................',
    // Absolute path to the writable images directory.
    'images_dir' => __DIR__ . '/public_html/images',
];
