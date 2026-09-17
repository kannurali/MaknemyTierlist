<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/deploy.php';

// --- signature ------------------------------------------------------------

function sig_for(string $body, string $secret): string {
    return 'sha256=' . hash_hmac('sha256', $body, $secret);
}

test('valid signature accepted', function () {
    $body = '{"ref":"refs/heads/master"}';
    assert_true(deploy_signature_valid($body, sig_for($body, 's3cret'), 's3cret'), 'match');
});

test('tampered body rejected', function () {
    $sig = sig_for('{"ref":"refs/heads/master"}', 's3cret');
    assert_eq(false, deploy_signature_valid('{"ref":"refs/heads/evil"}', $sig, 's3cret'), 'body changed');
});

test('wrong secret rejected', function () {
    $body = '{"a":1}';
    assert_eq(false, deploy_signature_valid($body, sig_for($body, 'other'), 's3cret'), 'secret changed');
});

test('missing signature header rejected', function () {
    assert_eq(false, deploy_signature_valid('{}', null, 's3cret'), 'no header');
    assert_eq(false, deploy_signature_valid('{}', '', 's3cret'), 'empty header');
});

// An empty configured secret must never validate — otherwise an unconfigured
// install would accept any request that computes the HMAC of the empty key.
test('empty secret never validates', function () {
    $body = '{}';
    assert_eq(false, deploy_signature_valid($body, sig_for($body, ''), ''), 'unconfigured');
});

// --- payload decoding -----------------------------------------------------

test('json body decoded', function () {
    $p = deploy_payload_from_request('{"ref":"refs/heads/master"}', 'application/json');
    assert_eq('refs/heads/master', $p['ref'] ?? '', 'ref read');
});

test('form-encoded body decoded', function () {
    $raw = 'payload=' . urlencode('{"ref":"refs/heads/master"}');
    $p = deploy_payload_from_request($raw, 'application/x-www-form-urlencoded');
    assert_eq('refs/heads/master', $p['ref'] ?? '', 'ref read from payload field');
});

test('garbage body decodes to empty array', function () {
    assert_eq([], deploy_payload_from_request('not json', 'application/json'), 'no crash');
});

// --- trigger decision -----------------------------------------------------

test('push to the deploy branch runs', function () {
    $v = deploy_should_run('push', ['ref' => 'refs/heads/master'], 'master');
    assert_true($v['run'], 'runs');
});

test('push to another branch is ignored', function () {
    $v = deploy_should_run('push', ['ref' => 'refs/heads/main'], 'master');
    assert_eq(false, $v['run'], 'no run');
    assert_eq('ignored_ref:refs/heads/main', $v['reason'], 'reason logged');
});

test('tag push is ignored', function () {
    $v = deploy_should_run('push', ['ref' => 'refs/tags/v1'], 'master');
    assert_eq(false, $v['run'], 'no run');
});

test('ping answered without deploying', function () {
    $v = deploy_should_run('ping', [], 'master');
    assert_eq(false, $v['run'], 'no run');
    assert_eq('pong', $v['reason'], 'pong');
});

test('non-push event ignored', function () {
    $v = deploy_should_run('issues', ['ref' => 'refs/heads/master'], 'master');
    assert_eq(false, $v['run'], 'no run');
});

test('branch deletion does not deploy', function () {
    $v = deploy_should_run('push', ['ref' => 'refs/heads/master', 'deleted' => true], 'master');
    assert_eq(false, $v['run'], 'no run');
    assert_eq('branch_deleted', $v['reason'], 'reason');
});

// --- path guard -----------------------------------------------------------

test('normal repo/target pair is sane', function () {
    assert_true(deploy_paths_sane('/home/u/repositories/Nexus', '/home/u/public_html'), 'disjoint');
});

test('target inside the repo is refused', function () {
    assert_eq(false, deploy_paths_sane('/home/u/repo', '/home/u/repo/public_html'), 'nested');
});

test('identical paths refused', function () {
    assert_eq(false, deploy_paths_sane('/home/u/repo', '/home/u/repo/'), 'same after normalising');
});

test('empty paths refused', function () {
    assert_eq(false, deploy_paths_sane('', '/home/u/public_html'), 'no repo');
    assert_eq(false, deploy_paths_sane('/home/u/repo', ''), 'no target');
});

// A sibling directory whose name merely starts with the repo name must not be
// mistaken for a nested path.
test('sibling with a shared name prefix is allowed', function () {
    assert_true(deploy_paths_sane('/home/u/repo', '/home/u/repo-live'), 'not nested');
});

// --- publishing -----------------------------------------------------------

function pub_tmp(): string {
    $d = str_replace('\\', '/', sys_get_temp_dir()) . '/nx-publish-' . bin2hex(random_bytes(5));
    mkdir($d, 0777, true);
    return $d;
}

function pub_put(string $path, string $body, ?int $mtime = null): void {
    if (!is_dir(dirname($path))) { mkdir(dirname($path), 0777, true); }
    file_put_contents($path, $body);
    if ($mtime !== null) { touch($path, $mtime); }
}

function pub_rm(string $dir): void {
    if (!is_dir($dir)) { return; }
    foreach (scandir($dir) as $n) {
        if ($n === '.' || $n === '..') { continue; }
        $p = $dir . '/' . $n;
        if (is_dir($p)) { pub_rm($p); } else { unlink($p); }
    }
    rmdir($dir);
}

function pub_publish_error(string $src, string $dst): ?Throwable {
    try {
        deploy_publish($src, $dst);
    } catch (Throwable $e) {
        return $e;
    }
    return null;
}

function pub_files(string $dir, string $prefix = ''): array {
    $out = [];
    foreach (scandir($dir) as $n) {
        if ($n === '.' || $n === '..') { continue; }
        $p = $dir . '/' . $n;
        if (is_dir($p)) {
            $out = array_merge($out, pub_files($p, $prefix . $n . '/'));
        } else {
            $out[] = $prefix . $n;
        }
    }
    sort($out);
    return $out;
}

// Pages reference css/ and js/ as name.js?v=N, and .htaccess lets browsers
// keep such an address for a year. Static directories therefore go live
// before any page that could point at their new ?v= numbers.
test('publish order puts static directories before pages', function () {
    $order = deploy_publish_order(['.', '..', 'index.php', 'js', '.htaccess', 'api', 'css', 'assets', 'home.php']);
    assert_eq(['assets', 'css', 'js', '.htaccess', 'api', 'home.php', 'index.php'], $order,
        'static first, then the rest by name');
});

test('publish order tolerates a missing static directory', function () {
    assert_eq(['js', 'index.php'], deploy_publish_order(['index.php', 'js']), 'no phantom css/assets');
});

test('publish copies new files and creates directories', function () {
    $src = pub_tmp();
    $dst = pub_tmp();
    pub_put("$src/index.php", 'page');
    pub_put("$src/.htaccess", 'rules');
    pub_put("$src/js/app.js", 'app');
    pub_put("$src/api/lib/x.php", 'lib');
    $r = deploy_publish($src, $dst);
    assert_eq(['.htaccess', 'api/lib/x.php', 'index.php', 'js/app.js'], pub_files($dst), 'tree reproduced');
    assert_eq('app', file_get_contents("$dst/js/app.js"), 'content copied');
    assert_eq(4, count($r['copied']), 'four files copied');
    assert_eq(0, $r['same'], 'nothing was there before');
    pub_rm($src);
    pub_rm($dst);
});

test('publish writes static files before pages', function () {
    $src = pub_tmp();
    $dst = pub_tmp();
    pub_put("$src/index.php", 'page');
    pub_put("$src/css/a.css", 'css');
    pub_put("$src/js/app.js", 'app');
    pub_put("$src/assets/x.png", 'png');
    $r = deploy_publish($src, $dst);
    assert_eq(['assets/x.png', 'css/a.css', 'js/app.js', 'index.php'], $r['copied'], 'copy order');
    pub_rm($src);
    pub_rm($dst);
});

// Admin uploads live only on the server (images/ is git-ignored), and a file
// removed from the repo is cleaned up by hand: publishing is additive.
test('publish never deletes anything on the target', function () {
    $src = pub_tmp();
    $dst = pub_tmp();
    pub_put("$src/images/.htaccess", 'img rules');
    pub_put("$dst/images/abc123.webp", 'upload');
    pub_put("$dst/js/old.js", 'stale');
    deploy_publish($src, $dst);
    assert_eq('upload', file_get_contents("$dst/images/abc123.webp"), 'upload kept');
    assert_eq('stale', file_get_contents("$dst/js/old.js"), 'file removed from the repo kept');
    pub_rm($src);
    pub_rm($dst);
});

// Last-Modified comes from the file's mtime. Rewriting identical bytes on
// every deploy would move it, and each revalidation would become a full
// download instead of a 304.
test('publish leaves an identical file untouched', function () {
    $src = pub_tmp();
    $dst = pub_tmp();
    pub_put("$src/js/app.js", 'same bytes');
    pub_put("$dst/js/app.js", 'same bytes', 1600000000);
    $r = deploy_publish($src, $dst);
    clearstatcache();
    assert_eq(1600000000, filemtime("$dst/js/app.js"), 'mtime kept');
    assert_eq([], $r['copied'], 'nothing copied');
    assert_eq(1, $r['same'], 'counted as unchanged');
    pub_rm($src);
    pub_rm($dst);
});

test('publish replaces a changed file', function () {
    $src = pub_tmp();
    $dst = pub_tmp();
    pub_put("$src/js/app.js", 'new code');
    pub_put("$dst/js/app.js", 'old code', 1600000000);
    $r = deploy_publish($src, $dst);
    clearstatcache();
    assert_eq('new code', file_get_contents("$dst/js/app.js"), 'content replaced');
    assert_true(filemtime("$dst/js/app.js") > 1600000000, 'mtime moved on, so Last-Modified changes');
    assert_eq(['js/app.js'], $r['copied'], 'reported as copied');
    pub_rm($src);
    pub_rm($dst);
});

test('publish compares bytes, not just sizes', function () {
    $src = pub_tmp();
    $dst = pub_tmp();
    pub_put("$src/css/a.css", 'aaaa');
    pub_put("$dst/css/a.css", 'bbbb');
    deploy_publish($src, $dst);
    assert_eq('aaaa', file_get_contents("$dst/css/a.css"), 'same length, different bytes: replaced');
    pub_rm($src);
    pub_rm($dst);
});

// Every file is written under a temporary name and renamed into place, so a
// request never reads a half-written file. None of those names may linger.
test('publish leaves no temporary files behind', function () {
    $src = pub_tmp();
    $dst = pub_tmp();
    pub_put("$src/js/app.js", 'new');
    pub_put("$src/index.php", 'page');
    pub_put("$dst/js/app.js", 'old');
    deploy_publish($src, $dst);
    assert_eq(['index.php', 'js/app.js'], pub_files($dst), 'only the published files');
    pub_rm($src);
    pub_rm($dst);
});

test('publish refuses a directory where the target has a file', function () {
    $src = pub_tmp();
    $dst = pub_tmp();
    pub_put("$src/js/app.js", 'app');
    pub_put("$dst/js", 'a file named js');
    assert_true(pub_publish_error($src, $dst) instanceof RuntimeException, 'type clash fails loudly');
    assert_eq('a file named js', file_get_contents("$dst/js"), 'target left as it was');
    pub_rm($src);
    pub_rm($dst);
});

test('publish refuses a file where the target has a directory', function () {
    $src = pub_tmp();
    $dst = pub_tmp();
    pub_put("$src/index.php", 'page');
    mkdir("$dst/index.php", 0777, true);
    assert_true(pub_publish_error($src, $dst) instanceof RuntimeException, 'type clash fails loudly');
    assert_true(is_dir("$dst/index.php"), 'target left as it was');
    pub_rm($src);
    pub_rm($dst);
});

test('publish reproduces the real public_html byte for byte', function () {
    $src = str_replace('\\', '/', dirname(__DIR__)) . '/public_html';
    $dst = pub_tmp();
    deploy_publish($src, $dst);
    $files = pub_files($src);
    assert_eq($files, pub_files($dst), 'same file list');
    $diff = 0;
    foreach ($files as $f) {
        if (sha1_file("$src/$f") !== sha1_file("$dst/$f")) { $diff++; }
    }
    assert_eq(0, $diff, 'identical contents');
    $again = deploy_publish($src, $dst);
    assert_eq([], $again['copied'], 'a second run copies nothing');
    pub_rm($dst);
});

// .cpanel.yml is the manual fallback (cPanel's "Deploy HEAD Commit"). It runs
// plain cp, but keeps the same order and does not reset mtimes.
test('.cpanel.yml publishes static directories before the rest', function () {
    $yml = file_get_contents(dirname(__DIR__) . '/.cpanel.yml');
    $static = strpos($yml, 'public_html/assets public_html/css public_html/js $DEPLOYPATH/');
    $all = strpos($yml, 'public_html/. $DEPLOYPATH/');
    assert_true($static !== false, 'static directories copied on their own');
    assert_true($all !== false, 'full copy still there');
    assert_true($static !== false && $all !== false && $static < $all, 'static directories first');
    assert_eq(2, substr_count($yml, '/bin/cp -R --preserve=timestamps '), 'both copies keep mtimes');
});

run_tests();
