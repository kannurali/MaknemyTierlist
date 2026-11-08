<?php
// Общее для tests/cm_*_test.php: база в памяти с таблицами чат-менеджера,
// запись вызовов Telegram, готовые сообщения группы и контекст обработчика.
require_once __DIR__ . '/cm_schema.php';

const CM_NOW   = 1790000000;
const CM_CHAT  = -1001234567890;
const CM_BOT   = 'MaknemyBot';
const CM_OWNER = 1;   // создатель группы в Telegram
const CM_ADMIN = 2;   // админ Telegram без ранга бота
// Смена суток по Москве (этап 2): 21.09.2026 23:59 и 22.09.2026 00:01 МСК.
const CM_T2359 = 1790024340;
const CM_T0001 = 1790024460;

// Подставная случайность (этап 3): $ctx['rand'] = cm_seq([…]) — числа по
// очереди, последнее повторяется. Число вне [$min, $max] — исключение: тест
// ошибся в ожиданиях.
function cm_seq(array $values): callable {
    return function (int $min, int $max) use (&$values): int {
        $v = count($values) > 1 ? array_shift($values) : $values[0];
        if ($v < $min || $v > $max) { throw new RuntimeException("случайное $v вне [$min, $max]"); }
        return $v;
    };
}

function cm_test_pdo(): PDO {
    $pdo = test_db();
    cm_sqlite_tables($pdo);
    return $pdo;
}

function cm_cfg(array $extra = []): array {
    return $extra + ['tg_bot_name' => CM_BOT, 'admin_ids' => ['900'], 'moderator_ids' => ['901']];
}

// Подставной $send: пишет вызовы в $log, отвечает из $answers (значение или
// функция от параметров), иначе — правдоподобным успехом.
function cm_rec(array &$log, array $answers = []): callable {
    return function (string $method, array $params) use (&$log, $answers): ?array {
        $log[] = [$method, $params];
        if (array_key_exists($method, $answers)) {
            $a = $answers[$method];
            return is_callable($a) ? $a($params) : $a;
        }
        if ($method === 'sendMessage') {
            return ['ok' => true, 'result' => ['message_id' => 5000 + count($log)]];
        }
        if ($method === 'getChatAdministrators') {
            return ['ok' => true, 'result' => [
                ['status' => 'creator', 'user' => ['id' => CM_OWNER, 'first_name' => 'Owner']],
                ['status' => 'administrator', 'user' => ['id' => CM_ADMIN, 'first_name' => 'Admin']],
            ]];
        }
        return ['ok' => true, 'result' => true];
    };
}

function cm_calls(array $log, string $method): array {
    return array_values(array_filter($log, function ($c) use ($method) { return $c[0] === $method; }));
}

function cm_texts(array $log): array {
    return array_map(function ($c) { return (string)$c[1]['text']; }, cm_calls($log, 'sendMessage'));
}

function cm_last_text(array $log): string {
    $t = cm_texts($log);
    return $t ? (string)$t[count($t) - 1] : '';
}

function cm_gmsg(int $from, string $text, array $extra = []): array {
    static $id = 100;
    $id++;
    return $extra + [
        'message_id' => $id, 'date' => CM_NOW,
        'chat' => ['id' => CM_CHAT, 'type' => 'supergroup', 'title' => 'Тестовый чат'],
        'from' => ['id' => $from, 'is_bot' => false, 'first_name' => 'U' . $from, 'username' => 'user' . $from],
        'text' => $text,
    ];
}

// Человек, которого бот «видел»: есть в cm_users под ником.
function cm_person(PDO $pdo, int $id, string $username, string $name = ''): void {
    cm_user_touch($pdo, ['id' => $id, 'first_name' => $name !== '' ? $name : 'U' . $id, 'username' => $username], CM_NOW);
}

function cm_lvl(PDO $pdo, int $userId, int $lvl, int $chatId = CM_CHAT): void {
    cm_member_set($pdo, $chatId, $userId, ['lvl' => $lvl]);
}

// Контекст обработчика для сообщения $text от $actor в тестовой группе.
function cm_ctx_for(PDO $pdo, array &$log, int $actor, string $text = '', array $extra = [], array $answers = [], int $now = CM_NOW): array {
    $msg  = cm_gmsg($actor, $text, $extra);
    $chat = cm_chat_ensure($pdo, $msg['chat'], $now);
    $ctx  = cm_ctx($pdo, cm_cfg(), cm_rec($log, $answers), $chat, $msg, $now);
    cm_admins_refresh($ctx);
    $ctx['rank'] = cm_rank($ctx, $actor);
    return $ctx;
}

// Выполнить команду обработчиком напрямую — как роутер после проверки ранга.
// Строка чата перечитывается, как на бою при каждом апдейте: иначе вторая
// настройка подряд затёрла бы первую.
function cm_do(array $ctx, string $text): void {
    $cmd = cm_parse($text, CM_BOT);
    if ($cmd === null) { throw new RuntimeException('не команда: ' . $text); }
    if (empty($ctx['chat']['private'])) {
        $ctx['chat'] = cm_chat_row($ctx['pdo'], (int)$ctx['chat']['chat_id']) ?? $ctx['chat'];
    }
    $ctx['msg']['text'] = $text;
    $fn = 'cm_cmd_' . $cmd['handler'];
    $fn($ctx, $cmd);
}
