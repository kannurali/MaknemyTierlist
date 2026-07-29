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

    // --- GitHub push webhook (api/deploy.php) ---------------------------
    // Leave 'deploy_secret' empty to keep the endpoint disabled: it then
    // answers 503 and never runs anything.
    // Generate: php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
    // Paste the same value into the webhook's Secret field on GitHub.
    'deploy_secret' => '',
    // Absolute path of the cPanel Git clone (NOT the web root).
    'deploy_repo'   => '/home/maknemyt/repositories/NexusTierlist',
    // Web root the repo's public_html/ is copied into.
    'deploy_path'   => '/home/maknemyt/public_html',
    'deploy_branch' => 'master',
    // Optional: absolute path to git if auto-detection fails.
    // 'deploy_git'  => '/usr/local/cpanel/3rdparty/bin/git',
    // Optional: defaults to deploy.log next to this file.
    // 'deploy_log'  => '/home/maknemyt/deploy.log',
];
