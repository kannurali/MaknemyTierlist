<?php
// One-time migration: shrink icons uploaded before ICON_MAX_SIDE existed.
//
// Icons were stored at their source resolution (up to 800x800) but paint at
// ~130 device px, so WebKit held ~63 MB of decoded bitmaps for a full tier
// list — enough for Safari to start evicting them mid-scroll.
//
// Run from the repository root, on the machine that holds the live DB:
//   php tools/downscale-images.php --dry-run   report only, writes nothing
//   php tools/downscale-images.php             resize, repoint the state, bump rev
//
// Safe to re-run: images already within the cap are skipped. Originals are never
// deleted — /images/ is served with an immutable cache header, so resized bytes
// must live under a new content-hash name. Unreferenced originals are listed at
// the end for manual cleanup.

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
define('CONFIG_PATH', $root . '/config.php');
require $root . '/public_html/api/_bootstrap.php';
require $root . '/public_html/api/lib/images.php';

$dryRun = in_array('--dry-run', $argv, true);
$dir = app_config()['images_dir'];
if (!is_dir($dir)) {
    fwrite(STDERR, "images_dir not found: $dir\n");
    exit(1);
}

$pdo = db();
$state = json_decode((string)$pdo->query("SELECT data FROM tierlist WHERE id = 1")->fetchColumn(), true);
if (!is_array($state) || !isset($state['tiers'])) {
    fwrite(STDERR, "tierlist row holds no usable state - nothing to migrate\n");
    exit(1);
}

printf("images dir: %s\ncap: %d px on the long side\nmode: %s\n\n",
    $dir, ICON_MAX_SIDE, $dryRun ? 'DRY RUN (nothing is written)' : 'write');

[$newState, $stats] = downscale_stored_images($state, $dir, ICON_MAX_SIDE, !$dryRun);

printf("references scanned:  %d\nicons to resize:     %d\nabsolute urls fixed: %d\ndisk saved:          %.0f KB\n",
    $stats['scanned'], $stats['resized'], $stats['relativised'], $stats['savedBytes'] / 1024);

if ($stats['skipped']) {
    echo "\nskipped:\n";
    foreach ($stats['skipped'] as $s) { echo "  - $s\n"; }
}

if ($dryRun) {
    echo "\ndry run complete - files and DB untouched\n";
    exit(0);
}
if ($stats['resized'] === 0 && $stats['relativised'] === 0) {
    echo "\nevery icon is already within the cap and relative - DB left alone\n";
    exit(0);
}

// rev has to change: clients cache /api/tierlist.php?rev=<n> as immutable, so
// without a new rev nobody would ever request the repointed state.
$rev = (int)round(microtime(true) * 1000);
$newState['_rev'] = $rev;
$pdo->prepare("UPDATE tierlist SET data = :d, rev = :r WHERE id = 1")->execute([
    ':d' => json_encode($newState, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ':r' => $rev,
]);

printf("\nDB updated, rev = %d\n", $rev);
echo "\nThe originals are still on disk and no longer referenced.\n";
echo "Check the site first, then remove them:\n";
foreach ($stats['orphans'] as $o) { echo "  rm " . rtrim($dir, '/\\') . "/$o\n"; }
