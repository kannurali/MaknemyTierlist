<?php
function validate_state($state, int $maxBytes = 524288): array {
    if (!is_array($state)) {
        return ['ok' => false, 'error' => 'state must be a JSON object'];
    }
    if (!isset($state['tiers']) || !is_array($state['tiers'])) {
        return ['ok' => false, 'error' => 'missing tiers array'];
    }
    $encoded = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        return ['ok' => false, 'error' => 'state is not serializable'];
    }
    if (strlen($encoded) > $maxBytes) {
        return ['ok' => false, 'error' => 'state too large (' . strlen($encoded) . ' bytes)'];
    }
    return ['ok' => true, 'error' => ''];
}
