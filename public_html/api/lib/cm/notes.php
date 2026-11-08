<?php
// Заметки: готовые ответы по «#имя». Текст хранится с разметкой Telegram.

const CM_NOTES_MAX = 50;

function cm_note_name(string $raw): string {
    return preg_match('~^#?([а-яa-z0-9_]{1,32})$~u', cm_norm(trim($raw)), $m) ? $m[1] : '';
}

function cm_cmd_note_add(array $ctx, array $cmd): void {
    $name = cm_note_name($cmd['args']);
    $body = $cmd['body'];
    if ($name === '' || trim($body) === '' || mb_strlen($body) > 4000) {
        cm_reply($ctx, cm_t('note_usage'));
        return;
    }
    $pdo = $ctx['pdo'];
    $chatId = (int)$ctx['chat']['chat_id'];
    $p = [':c' => $chatId, ':n' => $name, ':b' => $body,
          ':e' => json_encode(cm_body_entities($ctx['msg']), JSON_UNESCAPED_UNICODE), ':by' => (int)$ctx['actor'], ':at' => (int)$ctx['now']];
    $up = $pdo->prepare('UPDATE cm_notes SET body = :b, entities = :e, by_id = :by, updated_at = :at WHERE chat_id = :c AND name = :n');
    $up->execute($p);
    if ($up->rowCount() === 0) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM cm_notes WHERE chat_id = :c');
        $st->execute([':c' => $chatId]);
        if ((int)$st->fetchColumn() >= CM_NOTES_MAX) {
            cm_reply($ctx, cm_t('notes_full', ['max' => CM_NOTES_MAX]));
            return;
        }
        try {
            $pdo->prepare('INSERT INTO cm_notes (chat_id, name, body, entities, by_id, updated_at) VALUES (:c, :n, :b, :e, :by, :at)')->execute($p);
        } catch (PDOException $e) {
            // та же заметка в ту же секунду: строка уже есть
        }
    }
    cm_reply($ctx, cm_t('note_saved', ['name' => $name]), false);
}

function cm_cmd_note_del(array $ctx, array $cmd): void {
    $name = cm_note_name($cmd['args']);
    $st = $ctx['pdo']->prepare('DELETE FROM cm_notes WHERE chat_id = :c AND name = :n');
    $st->execute([':c' => $ctx['chat']['chat_id'], ':n' => $name]);
    cm_reply($ctx, cm_t($st->rowCount() > 0 ? 'note_removed' : 'note_missing', ['name' => $name]), $st->rowCount() === 0);
}

function cm_cmd_note_list(array $ctx, array $cmd): void {
    $st = $ctx['pdo']->prepare('SELECT name FROM cm_notes WHERE chat_id = :c ORDER BY name');
    $st->execute([':c' => $ctx['chat']['chat_id']]);
    $names = array_map(function ($n) { return '#' . $n; }, $st->fetchAll(PDO::FETCH_COLUMN));
    cm_reply($ctx, $names ? cm_t('notes_head') . "\n" . implode(' · ', $names) : cm_t('notes_empty'), false);
}

// «#имя» в начале сообщения → текст заметки ответом.
function cm_note_try(array $ctx): bool {
    $text = (string)($ctx['msg']['text'] ?? '');
    if (!preg_match('~^#([^\s#]{1,32})~u', $text, $m)) { return false; }
    $name = cm_note_name($m[1]);
    if ($name === '') { return false; }
    $st = $ctx['pdo']->prepare('SELECT body, entities FROM cm_notes WHERE chat_id = :c AND name = :n');
    $st->execute([':c' => $ctx['chat']['chat_id'], ':n' => $name]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) { return false; }
    $ents = json_decode((string)($row['entities'] ?? ''), true);
    cm_reply($ctx, (string)$row['body'], false, is_array($ents) && $ents ? ['entities' => $ents] : []);
    return true;
}
