<?php
// Image helpers: type sniffing, data-url decode, hashed save, embedded extraction.

function image_ext_for(string $bytes): ?string {
    if (strncmp($bytes, "\x89PNG\r\n\x1a\n", 8) === 0) { return 'png'; }
    if (strncmp($bytes, "\xFF\xD8\xFF", 3) === 0) { return 'jpg'; }
    if (strncmp($bytes, 'RIFF', 4) === 0 && substr($bytes, 8, 4) === 'WEBP') { return 'webp'; }
    return null;
}

function data_url_to_bytes(string $dataUrl): ?string {
    if (strncmp($dataUrl, 'data:', 5) !== 0) { return null; }
    $comma = strpos($dataUrl, ',');
    if ($comma === false) { return null; }
    $meta = substr($dataUrl, 5, $comma - 5);
    $payload = substr($dataUrl, $comma + 1);
    if (strpos($meta, ';base64') !== false) {
        $decoded = base64_decode($payload, true);
        return $decoded === false ? null : $decoded;
    }
    return rawurldecode($payload);
}

function save_image_bytes(string $bytes, string $dir, int $maxBytes = 512000): string {
    if (strlen($bytes) > $maxBytes) {
        throw new RuntimeException('image too large');
    }
    $ext = image_ext_for($bytes);
    if ($ext === null) {
        throw new RuntimeException('unsupported image type');
    }
    if (!is_dir($dir)) { mkdir($dir, 0755, true); }
    $name = sha1($bytes) . '.' . $ext;
    $path = rtrim($dir, '/\\') . '/' . $name;
    if (!file_exists($path)) {
        if (file_put_contents($path, $bytes) === false) {
            throw new RuntimeException('failed to write image');
        }
    }
    return '/images/' . $name;
}

function extract_embedded_images(array $state, string $dir): array {
    $rewrite = function ($val) use ($dir) {
        if (is_string($val) && strncmp($val, 'data:', 5) === 0) {
            $bytes = data_url_to_bytes($val);
            if ($bytes !== null) { return save_image_bytes($bytes, $dir); }
        }
        return $val;
    };
    if (isset($state['tiers']) && is_array($state['tiers'])) {
        foreach ($state['tiers'] as &$tier) {
            if (isset($tier['logo'])) { $tier['logo'] = $rewrite($tier['logo']); }
            if (isset($tier['items']) && is_array($tier['items'])) {
                foreach ($tier['items'] as &$item) {
                    if (isset($item['icon'])) { $item['icon'] = $rewrite($item['icon']); }
                }
                unset($item);
            }
        }
        unset($tier);
    }
    if (isset($state['ad']['image'])) {
        $state['ad']['image'] = $rewrite($state['ad']['image']);
    }
    return $state;
}
