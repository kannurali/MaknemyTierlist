<?php
// Advertising campaigns: their own document, their own revision, their own
// table row.
//
// НЕ переносить в state / tierlist.data. Three reasons, all of them things
// that already bit us:
//   1. Every image inside `state` goes through walk_state_images() ->
//      save_image_bytes() -> 256 px. A banner stored there is destroyed.
//   2. save.php writes the whole blob with no optimistic concurrency, so an
//      ad edit made in one tab would silently discard tier edits made in
//      another.
//   3. tierlist.php is served with an immutable year-long cache. Bumping its
//      rev to swap a creative forces every visitor to re-download the entire
//      tier list; a separate document costs a few kilobytes.
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/images.php';

// Serialized ceiling for the whole document. Generous - a campaign is roughly
// 450 bytes - but it stops a runaway admin client from filling a LONGTEXT.
const PROMO_MAX_BYTES = 65536;

const PROMO_EMPTY = ['v' => 1, 'rev' => 0, 'campaigns' => []];

// Mirror of PROMO.safeHref() in js/promo.js. The admin page is not a trust
// boundary: whatever it sends, the stored href is validated here.
function promo_safe_href(string $href): string {
    $s = trim($href);
    if ($s === '' || preg_match('~^https?:/*$~i', $s)) { return ''; }
    if (preg_match('~[\s<>"]~', $s)) { return ''; }
    if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $s)) {
        return preg_match('~^(https?:|mailto:|tel:)~i', $s) ? $s : '';
    }
    if (strncmp($s, '//', 2) === 0) { return 'https:' . $s; }
    if ($s[0] === '/' || $s[0] === '#') { return $s; }
    return 'https://' . ltrim($s, '/');
}

function promo_clamp_int($v, int $min, int $max, int $dflt): int {
    if (!is_numeric($v)) { return $dflt; }
    return max($min, min($max, (int)round((float)$v)));
}

function promo_str($v): string {
    return is_scalar($v) ? trim((string)$v) : '';
}

// Normalizes AND validates. Hard failures are the ones a client cannot mean
// by accident and that would corrupt the document: a missing or malformed id,
// or a duplicate one (ids are the join key for click statistics later, so they
// have to stay unique and stable). Everything else is clamped silently - an
// out-of-range weight is a slider mistake, not a reason to lose the edit.
function promo_normalize(array $doc): array {
    $slots = array_keys(CREATIVE_SPECS);
    $rawCampaigns = isset($doc['campaigns']) && is_array($doc['campaigns']) ? $doc['campaigns'] : [];

    $out = [];
    $seenIds = [];
    foreach ($rawCampaigns as $i => $c) {
        if (!is_array($c)) {
            return ['ok' => false, 'error' => "campaign #$i is not an object", 'doc' => PROMO_EMPTY];
        }
        $id = promo_str($c['id'] ?? '');
        if (!preg_match('~^[A-Za-z0-9_-]{1,64}$~', $id)) {
            return ['ok' => false, 'error' => "campaign #$i has an invalid id", 'doc' => PROMO_EMPTY];
        }
        if (isset($seenIds[$id])) {
            return ['ok' => false, 'error' => "duplicate campaign id: $id", 'doc' => PROMO_EMPTY];
        }
        $seenIds[$id] = true;

        $campSlots = [];
        foreach ((array)($c['slots'] ?? []) as $s) {
            $s = promo_str($s);
            if (in_array($s, $slots, true) && !in_array($s, $campSlots, true)) { $campSlots[] = $s; }
        }

        $creatives = [];
        $rawCre = is_array($c['creatives'] ?? null) ? $c['creatives'] : [];
        foreach ($slots as $slot) {
            if (!is_array($rawCre[$slot] ?? null)) { continue; }
            $src = promo_str($rawCre[$slot]['src'] ?? '');
            if ($src === '') { continue; }
            $anim = ($rawCre[$slot]['anim'] ?? false) === true;
            $poster = promo_str($rawCre[$slot]['poster'] ?? '');
            $creatives[$slot] = [
                'src'    => $src,
                'w'      => promo_clamp_int($rawCre[$slot]['w'] ?? 0, 0, 20000, 0),
                'h'      => promo_clamp_int($rawCre[$slot]['h'] ?? 0, 0, 20000, 0),
                'anim'   => $anim,
                // An animation without a still frame breaks reduced-motion,
                // the pause button and the PNG export, so fall back to itself.
                'poster' => $poster !== '' ? $poster : ($anim ? $src : ''),
            ];
        }

        $date = function ($v) {
            $s = promo_str($v);
            return preg_match('~^\d{4}-\d{2}-\d{2}$~', $s) ? $s : '';
        };
        $pop = is_array($c['popup'] ?? null) ? $c['popup'] : [];

        $out[] = [
            'id'         => $id,
            'name'       => promo_str($c['name'] ?? '') !== '' ? promo_str($c['name']) : $id,
            'advertiser' => promo_str($c['advertiser'] ?? ''),
            'enabled'    => ($c['enabled'] ?? true) !== false,
            'weight'     => promo_clamp_int($c['weight'] ?? 1, 1, 100, 1),
            'start'      => $date($c['start'] ?? ''),
            'end'        => $date($c['end'] ?? ''),
            'href'       => promo_safe_href(promo_str($c['href'] ?? '')),
            'text'       => promo_str($c['text'] ?? ''),
            'cta'        => promo_str($c['cta'] ?? ''),
            'slots'      => $campSlots,
            'creatives'  => $creatives,
            'popup'      => [
                'delayMs'    => promo_clamp_int($pop['delayMs'] ?? 12000, 5000, 60000, 12000),
                'capHours'   => promo_clamp_int($pop['capHours'] ?? 24, 1, 720, 24),
                'maxPerWeek' => promo_clamp_int($pop['maxPerWeek'] ?? 3, 1, 50, 3),
            ],
            'notes'      => promo_str($c['notes'] ?? ''),
        ];
    }

    return ['ok' => true, 'error' => '', 'doc' => ['v' => 1, 'rev' => 0, 'campaigns' => $out]];
}

// `notes` holds what the advertiser paid, until when, and who to contact.
// Publishing the owner's commercial terms with the banner would be a
// self-inflicted wound, so the public view drops it.
function promo_public_view(array $doc): array {
    if (!isset($doc['campaigns']) || !is_array($doc['campaigns'])) { return $doc; }
    foreach ($doc['campaigns'] as &$c) {
        if (is_array($c)) { unset($c['notes']); }
    }
    unset($c);
    return $doc;
}

// Missing table or missing row both mean "no campaigns yet", not an error.
// The promo table is created by schema.sql, which is run by hand; the site
// must keep serving if that step has not happened yet.
function promo_load(PDO $pdo): array {
    try {
        $row = $pdo->query("SELECT data, rev FROM promo WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return PROMO_EMPTY;
    }
    if (!$row) { return PROMO_EMPTY; }
    $doc = json_decode((string)$row['data'], true);
    if (!is_array($doc)) { return PROMO_EMPTY; }
    $doc['v'] = 1;
    $doc['rev'] = (int)$row['rev'];
    if (!isset($doc['campaigns']) || !is_array($doc['campaigns'])) { $doc['campaigns'] = []; }
    return $doc;
}

function promo_rev(PDO $pdo): int {
    try {
        return (int)$pdo->query("SELECT rev FROM promo WHERE id = 1")->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

// Pulls any inline data: URL out into /images/ before storing. The admin page
// uploads first and sends URLs, so this is a backstop - but it has to know the
// slot to pick the right size box, which a walk_promo_images() callback cannot
// carry, hence the explicit loop.
function promo_extract_embedded(array $doc, string $imagesDir): array {
    if (!isset($doc['campaigns']) || !is_array($doc['campaigns'])) { return $doc; }
    foreach ($doc['campaigns'] as &$camp) {
        if (!is_array($camp['creatives'] ?? null)) { continue; }
        foreach ($camp['creatives'] as $slot => &$cre) {
            if (!is_array($cre) || !isset(CREATIVE_SPECS[$slot])) { continue; }
            foreach (['src', 'poster'] as $field) {
                $val = $cre[$field] ?? '';
                if (!is_string($val) || strncmp($val, 'data:', 5) !== 0) { continue; }
                $bytes = data_url_to_bytes($val);
                if ($bytes === null) { throw new RuntimeException('unreadable inline creative'); }
                $saved = save_creative_bytes($bytes, $imagesDir, $slot);
                $cre[$field] = $saved['url'];
                if ($field === 'src') {
                    $cre['w'] = $saved['w'];
                    $cre['h'] = $saved['h'];
                    $cre['anim'] = $saved['anim'];
                }
            }
        }
        unset($cre);
    }
    unset($camp);
    return $doc;
}

function handle_promo_get(PDO $pdo, bool $admin): array {
    $doc = promo_load($pdo);
    return [200, $admin ? $doc : promo_public_view($doc)];
}

// Who gets the admin document - the one that still carries `notes`, i.e. what
// the advertiser paid and until when.
//
// Only the panel, and only on its own uncached request. The ?rev= URL is
// answered with `public, immutable`, and the owner browses the site with an
// admin session like everyone else: without this split, one page load stores
// the commercial terms in a response any shared cache may hand to the next
// visitor. The panel never uses ?rev= (see API_PROMO in js/promo-admin.js),
// so it loses nothing.
function promo_admin_view_allowed(bool $admin, bool $hasRev): bool {
    return $admin && !$hasRev;
}

// $expectRev is the revision the admin page loaded. Passing -1 skips the
// check. The tier list blob never got optimistic concurrency and quietly
// loses edits because of it; there is no reason to repeat that here.
function handle_promo_save(PDO $pdo, array $doc, string $imagesDir, int $revMs, int $expectRev = -1): array {
    $norm = promo_normalize($doc);
    if (!$norm['ok']) { return [400, ['ok' => false, 'error' => $norm['error']]]; }
    $doc = $norm['doc'];

    $current = promo_rev($pdo);
    if ($expectRev >= 0 && $expectRev !== $current) {
        return [409, ['ok' => false, 'error' => 'stale_rev', 'rev' => $current]];
    }

    try {
        $doc = promo_extract_embedded($doc, $imagesDir);
    } catch (RuntimeException $e) {
        return [400, ['ok' => false, 'error' => $e->getMessage()]];
    }

    $doc['rev'] = $revMs;
    $json = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (strlen($json) > PROMO_MAX_BYTES) {
        return [400, ['ok' => false, 'error' => 'promo document too large: ' . strlen($json)
            . ' bytes, max ' . PROMO_MAX_BYTES]];
    }

    // INSERT IGNORE (MySQL) and INSERT OR IGNORE (SQLite) are not portable and
    // the suite runs on SQLite, so branch in PHP instead.
    try {
        $exists = $pdo->query("SELECT id FROM promo WHERE id = 1")->fetchColumn();
        if ($exists === false) {
            $stmt = $pdo->prepare("INSERT INTO promo (id, data, rev) VALUES (1, :d, :r)");
        } else {
            $stmt = $pdo->prepare("UPDATE promo SET data = :d, rev = :r WHERE id = 1");
        }
        $stmt->execute([':d' => $json, ':r' => $revMs]);
    } catch (PDOException $e) {
        return [500, ['ok' => false, 'error' => 'promo table missing - run schema.sql']];
    }

    return [200, ['ok' => true, 'rev' => $revMs]];
}

if (!defined('TESTING')) {
    start_admin_session();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        // Same cache split as tierlist.php: a ?rev= URL never changes its
        // bytes, so it can be cached forever and a repeat poll costs nothing.
        $hasRev = isset($_GET['rev']) && $_GET['rev'] !== '';
        if ($hasRev) {
            header('Cache-Control: public, max-age=31536000, immutable');
        } else {
            header('Cache-Control: no-cache');
            // The body of this one depends on the session cookie.
            header('Vary: Cookie');
        }
        [$status, $payload] = handle_promo_get(db(), promo_admin_view_allowed(is_admin(), $hasRev));
    } else {
        require_post();
        require_admin();
        if (!rate_limit_allow('promo_save', (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 30, 60, time())) {
            json_out(['ok' => false, 'error' => 'rate_limited'], 429);
            exit;
        }
        $body = read_json_body();
        $expectRev = isset($body['expectRev']) && is_numeric($body['expectRev']) ? (int)$body['expectRev'] : -1;
        [$status, $payload] = handle_promo_save(
            db(),
            is_array($body['doc'] ?? null) ? $body['doc'] : [],
            app_config()['images_dir'],
            (int)round(microtime(true) * 1000),
            $expectRev
        );
    }
    json_out($payload, $status);
}
