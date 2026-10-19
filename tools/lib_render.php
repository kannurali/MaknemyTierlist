<?php
// Отрисовка публичной страницы отдельным процессом PHP — так, как её
// исполняет сервер: свой REQUEST_URI, свой $_GET, пустая SQLite в памяти.
// Пользуются tools/i18n_snapshot.php и тесты, которым нужна готовая разметка.
//
// Код для php -r собран только из одинарных кавычек: escapeshellarg на
// Windows заменяет двойные пробелами.

function nx_render_page(string $file, string $uri, array $get = []): string {
    $root = dirname(__DIR__);
    $cfg = sys_get_temp_dir() . '/nx_render_' . getmypid() . '_' . uniqid() . '_config.php';
    file_put_contents($cfg, '<?php return ' . var_export([
        'dsn' => 'sqlite::memory:', 'db_user' => null, 'db_pass' => null, 'images_dir' => '',
        'deploy_secret' => '', 'deploy_repo' => '', 'deploy_path' => '', 'deploy_branch' => '',
    ], true) . ";\n");
    $code = 'define(' . var_export('CONFIG_PATH', true) . ', $argv[2]);'
          . ' $_SERVER[' . var_export('REQUEST_URI', true) . '] = $argv[3];'
          . ' $_SERVER[' . var_export('HTTP_HOST', true) . '] = ' . var_export('maknemy.com', true) . ';'
          . ' $_GET = json_decode(base64_decode($argv[4]), true);'
          . ' require $argv[1];';
    $cmd = escapeshellarg(PHP_BINARY !== '' ? PHP_BINARY : 'php') . ' -r ' . escapeshellarg($code)
         . ' ' . escapeshellarg($root . '/public_html/' . $file)
         . ' ' . escapeshellarg($cfg)
         . ' ' . escapeshellarg($uri)
         . ' ' . escapeshellarg(base64_encode(json_encode((object)$get)))
         . (DIRECTORY_SEPARATOR === '/' ? ' 2>/dev/null' : ' 2>nul');
    $html = shell_exec($cmd);
    @unlink($cfg);
    return is_string($html) ? $html : '';
}
