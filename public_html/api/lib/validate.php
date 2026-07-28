<?php
// Structure only — deliberately NO size cap. Callers run this BEFORE image
// extraction: an embedded data: URL inflates the blob ~33%, so applying the
// size cap that early would reject a save that extraction is about to shrink
// to a plain URL.
function validate_structure($state): array {
    if (!is_array($state)) {
        return ['ok' => false, 'error' => 'state must be a JSON object'];
    }
    if (!isset($state['tiers']) || !is_array($state['tiers'])) {
        return ['ok' => false, 'error' => 'missing tiers array'];
    }
    return ['ok' => true, 'error' => ''];
}

// Structure + serialized size. Run this once images are external.
function validate_state($state, int $maxBytes = 524288): array {
    $s = validate_structure($state);
    if (!$s['ok']) { return $s; }
    $encoded = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        return ['ok' => false, 'error' => 'state is not serializable'];
    }
    if (strlen($encoded) > $maxBytes) {
        return ['ok' => false, 'error' => 'state too large (' . strlen($encoded) . ' bytes)'];
    }
    return ['ok' => true, 'error' => ''];
}
