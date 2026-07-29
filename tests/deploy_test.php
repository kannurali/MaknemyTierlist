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

run_tests();
