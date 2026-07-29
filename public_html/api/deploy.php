<?php
// GitHub push webhook -> pull the repo and republish public_html.
//
// This endpoint executes a deployment, so it is only ever reachable with a
// valid HMAC signature. The shared secret lives in config.php (above the web
// root, git-ignored) — the repository is public, so a secret committed here
// would be a secret handed to everybody.
//
// It deliberately does NOT call cPanel's own deploy queue: doing the two steps
// directly keeps the whole thing synchronous and debuggable, and needs no
// cPanel API token. The steps mirror .cpanel.yml exactly — an additive copy,
// never a delete-sync, because admin-uploaded images live only on the server.

require_once __DIR__ . '/_bootstrap.php';

// --- pure helpers (no side effects; unit-tested) ---------------------------

// Timing-safe comparison against GitHub's X-Hub-Signature-256 header. A plain
// === would leak the expected digest one byte at a time to a patient attacker.
function deploy_signature_valid(string $raw, ?string $header, string $secret): bool {
    if ($secret === '' || $header === null || $header === '') { return false; }
    $expected = 'sha256=' . hash_hmac('sha256', $raw, $secret);
    return hash_equals($expected, $header);
}

// GitHub sends either raw JSON or a form-encoded `payload` field, depending on
// how the hook was configured. The signature covers the raw body either way,
// so only the decoding differs.
function deploy_payload_from_request(string $raw, string $contentType): array {
    if (stripos($contentType, 'application/x-www-form-urlencoded') !== false) {
        parse_str($raw, $form);
        $raw = (string)($form['payload'] ?? '');
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

// Decides whether a delivery should trigger work. Returns the reason either
// way so the log explains why nothing happened.
function deploy_should_run(string $event, array $payload, string $branch): array {
    if ($event === 'ping')  { return ['run' => false, 'reason' => 'pong']; }
    if ($event !== 'push')  { return ['run' => false, 'reason' => 'ignored_event:' . $event]; }
    $ref = (string)($payload['ref'] ?? '');
    if ($ref !== 'refs/heads/' . $branch) { return ['run' => false, 'reason' => 'ignored_ref:' . $ref]; }
    // A branch deletion is a push with no new commit — nothing to publish.
    if (!empty($payload['deleted'])) { return ['run' => false, 'reason' => 'branch_deleted']; }
    return ['run' => true, 'reason' => 'push:' . $branch];
}

// Guards against a misconfigured config.php turning the copy into a recursive
// self-overwrite (target inside the repo, or target === repo).
function deploy_paths_sane(string $repo, string $target): bool {
    if ($repo === '' || $target === '') { return false; }
    $repo   = rtrim(str_replace('\\', '/', $repo), '/');
    $target = rtrim(str_replace('\\', '/', $target), '/');
    if ($repo === $target) { return false; }
    return strpos($target . '/', $repo . '/') !== 0;
}

// --- effectful helpers -----------------------------------------------------

function deploy_fn_enabled(string $fn): bool {
    if (!function_exists($fn)) { return false; }
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    return !in_array($fn, $disabled, true);
}

function deploy_run_cmd(string $cmd): array {
    $out = [];
    $code = 1;
    exec($cmd . ' 2>&1', $out, $code);
    return ['code' => $code, 'out' => trim(implode("\n", $out))];
}

// First candidate that can actually read the repo wins. cPanel ships its own
// git outside the usual PATH, so a bare `git` is not enough on every account.
function deploy_find_git(string $repo, ?string $configured): ?string {
    $candidates = $configured !== null && $configured !== ''
        ? [$configured]
        : ['/usr/local/cpanel/3rdparty/bin/git', '/usr/bin/git', 'git'];
    foreach ($candidates as $git) {
        $r = deploy_run_cmd(escapeshellarg($git) . ' -C ' . escapeshellarg($repo) . ' rev-parse --git-dir');
        if ($r['code'] === 0) { return $git; }
    }
    return null;
}

function deploy_log_line(string $file, string $msg): void {
    @file_put_contents($file, gmdate('c') . ' ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}

// --- request handling ------------------------------------------------------

if (!defined('TESTING')) {
    require_post();

    $cfg    = app_config();
    $secret = (string)($cfg['deploy_secret'] ?? '');
    $repo   = (string)($cfg['deploy_repo'] ?? '');
    $target = (string)($cfg['deploy_path'] ?? '');
    $branch = (string)($cfg['deploy_branch'] ?? 'master');
    $log    = (string)($cfg['deploy_log'] ?? dirname(CONFIG_PATH) . '/deploy.log');

    // Unconfigured means disabled, not "deploy with an empty secret".
    if ($secret === '' || !deploy_paths_sane($repo, $target)) {
        json_out(['ok' => false, 'error' => 'deploy_not_configured'], 503);
        exit;
    }

    $raw = (string)file_get_contents('php://input');
    $sig = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? null;
    if (!deploy_signature_valid($raw, is_string($sig) ? $sig : null, $secret)) {
        deploy_log_line($log, 'REJECT bad-signature from ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
        json_out(['ok' => false, 'error' => 'bad_signature'], 401);
        exit;
    }

    $event   = (string)($_SERVER['HTTP_X_GITHUB_EVENT'] ?? '');
    $payload = deploy_payload_from_request($raw, (string)($_SERVER['CONTENT_TYPE'] ?? ''));
    $verdict = deploy_should_run($event, $payload, $branch);

    if (!$verdict['run']) {
        deploy_log_line($log, 'SKIP ' . $verdict['reason']);
        json_out(['ok' => true, 'deployed' => false, 'reason' => $verdict['reason']], 200);
        exit;
    }

    if (!deploy_fn_enabled('exec')) {
        deploy_log_line($log, 'FAIL exec() disabled by php.ini');
        json_out(['ok' => false, 'error' => 'exec_disabled'], 500);
        exit;
    }

    $git = deploy_find_git($repo, $cfg['deploy_git'] ?? null);
    if ($git === null) {
        deploy_log_line($log, 'FAIL no usable git binary for ' . $repo);
        json_out(['ok' => false, 'error' => 'git_not_found'], 500);
        exit;
    }

    $started = microtime(true);
    $g = escapeshellarg($git) . ' -C ' . escapeshellarg($repo);

    // fetch + merge --ff-only rather than `pull`: it does not depend on the
    // branch's tracking config, and it fails loudly instead of creating a
    // merge commit if the server checkout ever diverges.
    $steps = [
        'fetch'  => $g . ' fetch --quiet origin ' . escapeshellarg($branch),
        'merge'  => $g . ' merge --ff-only FETCH_HEAD',
        'publish' => '/bin/cp -R ' . escapeshellarg(rtrim($repo, '/') . '/public_html/.')
                   . ' ' . escapeshellarg(rtrim($target, '/') . '/'),
    ];

    foreach ($steps as $name => $cmd) {
        $r = deploy_run_cmd($cmd);
        if ($r['code'] !== 0) {
            deploy_log_line($log, "FAIL $name (exit {$r['code']}): {$r['out']}");
            json_out(['ok' => false, 'error' => 'step_failed', 'step' => $name], 500);
            exit;
        }
    }

    $head = deploy_run_cmd($g . ' rev-parse HEAD')['out'];
    $secs = round(microtime(true) - $started, 2);
    deploy_log_line($log, "OK {$verdict['reason']} head=$head in {$secs}s");
    json_out(['ok' => true, 'deployed' => true, 'head' => $head, 'seconds' => $secs], 200);
}
