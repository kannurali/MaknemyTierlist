# Чат-менеджер, этап 1 (модерация) — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** наш Telegram-бот модерирует любые группы: ранги, варн/мут/кик/бан, фильтры с триггерами, приветствие и правила, репорты, общая база спамеров, чистка, заметки, таймеры.

**Architecture:** новый модуль `public_html/api/lib/cm/` — файлы по одной задаче, функции с явными параметрами (`PDO`, `$send`, `$now`), общий контекст `$ctx` для обработчиков команд. Вебхук отдаёт групповые апдейты в `cm_handle_update()` после ответа 200, cron раз в минуту гонит таймеры и автоудаление. Данные — в таблицах `cm_*` (одна миграция).

**Tech Stack:** PHP 7.4 (бой) / 8.0 (локально, XAMPP), MySQL на бою, SQLite в тестах, Telegram Bot API, собственный тестовый каркас `tests/lib.php`.

**Спека:** `docs/superpowers/specs/2026-11-07-chat-manager-moderation-design.md`.

## Global Constraints

- **PHP 7.4.** Нельзя: `str_contains`/`str_starts_with`/`str_ends_with`, `match`, `?->`, `#[...]`, union-типы (`int|string`), `mixed`, именованные аргументы, `catch` без переменной, `enum`, `readonly`. Можно: `fn() =>`, `??`, `??=`, типизированные параметры, `?int`. Проверка — `tests/php74_compat_test.php` (сканирует `public_html` рекурсивно).
- **Переносимый SQL.** Без `ON DUPLICATE KEY`, `ON CONFLICT`, `INSERT IGNORE`, `REPLACE`. Upsert: `UPDATE`, затем `INSERT` в `try/catch (PDOException $e)`. MySQL-ный `rowCount()` считает только изменённые строки.
- **Имена столбцов:** не `rank` (зарезервировано в MySQL 8), а `lvl`. «Нет срока», «не снято» — `0`, не `NULL`.
- **Ответы бота только на русском.** Все тексты — в `public_html/api/lib/cm/texts.php` (`CM_TEXTS`), из кода — через `cm_t()`.
- **Комментарии в PHP можно** (сервер их не отдаёт). В `public_html/js|css` и HTML — нельзя (здесь их не трогаем).
- **Файлы с `\`-экранированием (PHP, регулярки) писать только инструментом Write**, не heredoc и не Edit со вставкой `\u…`. После коммита — `git show --stat HEAD`: строк `Bin` быть не должно.
- **PHP для тестов:** `/c/xampp/php/php.exe` (Git Bash, из корня worktree). У XAMPP выключен GD, поэтому полный прогон — циклом с `-d extension=gd` (Task 13), а не `tests/run_all.sh`: тот с `set -e` встанет на `images_test.php`.
- **Ветка:** `feat/chat-manager-moderation` (worktree в скратчпаде). В `master` не пушить; в конце — push ветки и PR.
- **Коммиты** заканчиваются строкой `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- **Числа из SQLite в PHP 8.0 приходят строками** — в коде и тестах сравнивать через `(int)`.
- `max_connections` вебхука остаётся 5. По умолчанию сами работают только фильтры `spam` и `links`.

## Карта файлов

| Файл | Что | Задача |
| --- | --- | --- |
| `docs/migrations/2026-11-07-chat-manager.sql` | таблицы `cm_*` | 1 |
| `tests/cm_schema.php` | те же таблицы для SQLite (+ `tg_links`) | 1 |
| `tests/cm_helpers.php` | общие помощники тестов | 1 |
| `public_html/api/lib/cm/cm.php` | подключает все части | 1 (дополняется в каждой) |
| `public_html/api/lib/cm/db.php` | чаты, настройки, люди, участники, сообщения, переезд группы | 1 |
| `public_html/api/lib/cm/texts.php` | все тексты, `cm_t()`, `cm_date()`, `cm_reason_line()` | 1 |
| `public_html/api/lib/cm/parse.php` | таблица команд, разбор команды, сроки, цель, причина, UTF-16 | 2 |
| `public_html/api/lib/cm/send.php` | контекст, отправка, бюджет, ошибки Telegram, предупреждение о правах | 3 |
| `public_html/api/lib/cm/queue.php` | автоудаление, пакетное удаление | 3 |
| `public_html/api/lib/cm/ranks.php` | админы Telegram, ранг, доступ, команды рангов и `дк` | 3–4 |
| `public_html/api/lib/cm/punish.php` | наказания, варны, действия триггеров | 5 |
| `public_html/api/lib/cm/words.php` | встроенный мат и исключения | 6 |
| `public_html/api/lib/cm/filters.php` | фильтры, слова, домены, триггеры | 6 |
| `public_html/api/lib/cm/spam.php` | база спамеров | 7 |
| `public_html/api/lib/cm/welcome.php` | вход, приветствие, правила, статус бота в группе | 8 |
| `public_html/api/lib/cm/reports.php` | репорты | 9 |
| `public_html/api/lib/cm/cleanup.php` | чистка, массовые кики | 10 |
| `public_html/api/lib/cm/notes.php` | заметки | 11 |
| `public_html/api/lib/cm/timers.php` | команды таймеров | 11 |
| `public_html/api/lib/cm/router.php` | разбор апдейта, запуск команд, `помощь`/`кто я`/`id` | 12 |
| `public_html/api/lib/cm/cron.php` | тик таймеров и cron | 12 |
| `public_html/api/cm_cron.php` | запуск из cron | 12 |
| `public_html/api/tg_webhook.php` | маршрут в модуль | 12 |
| `public_html/api/lib/telegram.php` | `edited_message`, право удаления для групп | 12 |
| `public_html/.htaccess` | 404 на `api/cm_cron.php` | 12 |
| `tests/telegram_test.php` | новые ожидания `setWebhook` | 12 |
| `docs/chat-manager.md`, `docs/telegram-notify.md` | описание и запуск | 13 |

Тесты: `tests/cm_db_test.php`, `cm_parse_test.php`, `cm_core_test.php`, `cm_ranks_test.php`, `cm_punish_test.php`, `cm_filters_test.php`, `cm_spam_test.php`, `cm_welcome_test.php`, `cm_reports_test.php`, `cm_cleanup_test.php`, `cm_notes_test.php`, `cm_router_test.php`. Все подхватываются `tests/run_all.sh` по маске `*_test.php`.

Каждый тест-файл начинается одинаково:

```php
<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';
```

---

### Task 1: Таблицы, доступ к данным, тексты

**Files:**
- Create: `docs/migrations/2026-11-07-chat-manager.sql`
- Create: `tests/cm_schema.php`, `tests/cm_helpers.php`
- Create: `public_html/api/lib/cm/cm.php`, `public_html/api/lib/cm/db.php`, `public_html/api/lib/cm/texts.php`
- Test: `tests/cm_db_test.php`

**Interfaces:**
- Consumes: `test_db()` из `tests/lib.php`.
- Produces:
  - `CM_TZ`, `CM_MSG_KEEP` (172800), `CM_ADMINS_TTL` (3600), `CM_RANK_NAMES` (0..5), `CM_DEFAULTS`
  - `cm_enabled(array $cfg): bool`, `cm_ready(PDO $pdo): bool`
  - `cm_settings(array $raw): array` — слияние с `CM_DEFAULTS`
  - `cm_chat_decode(array $row): array`, `cm_chat_row(PDO $pdo, int $chatId): ?array`, `cm_chat_ensure(PDO $pdo, array $tgChat, int $now): array`, `cm_private_chat(int $chatId): array`, `cm_settings_save(PDO $pdo, array &$chat, array $settings, int $now): void`
  - Строка чата: `chat_id, title, username, active, owner_id, tg_owner_id, admins (int[]), admins_at, settings (array), report_chat_id, rights_warned_at, sent_window, sent_count, added_at, updated_at, private (bool)`
  - `cm_display_name(array $u): string`, `cm_user_touch(PDO $pdo, array $from, int $now): void`, `cm_user_find(PDO $pdo, string $username): ?array` (`['user_id' => int, 'name' => string]`), `cm_user_name(PDO $pdo, int $id): string`
  - `cm_member(PDO $pdo, int $chatId, int $userId): ?array`, `cm_member_set(PDO $pdo, int $chatId, int $userId, array $fields): void` (поля `lvl, joined_at, last_seen, greeted_at, left_at`), `cm_member_touch(PDO $pdo, int $chatId, int $userId, int $now, bool $joined = false): void`
  - `cm_text_hash(string $text): string`, `cm_msg_log(PDO $pdo, int $chatId, int $msgId, int $userId, string $text, int $now): void`
  - `cm_migrate(PDO $pdo, int $old, int $new): void`
  - `CM_TEXTS`, `cm_t(string $key, array $vars = []): string` (подстановки `{ключ}`), `cm_date(int $ts): string` (`d.m H:i` по Москве), `cm_reason_line(string $reason): string`
  - Тесты: `cm_sqlite_tables(PDO)`, `CM_NOW`, `CM_CHAT`, `CM_BOT`, `CM_OWNER` (1), `CM_ADMIN` (2), `cm_test_pdo()`, `cm_cfg()`, `cm_rec(&$log, $answers)`, `cm_calls()`, `cm_texts()`, `cm_last_text()`, `cm_gmsg()`, `cm_person()`, `cm_ctx_for()`, `cm_do()`, `cm_lvl()`

- [ ] **Step 1: Миграция**

Write `docs/migrations/2026-11-07-chat-manager.sql`:

```sql
-- Чат-менеджер, этап 1 (модерация): api/lib/cm/*, описание — docs/chat-manager.md.
-- Запускать один раз на боевой базе. Пока таблиц нет, модуль молчит
-- (cm_ready в api/lib/cm/db.php) и бот в группах ведёт себя как раньше.
--
-- «Нет срока» и «не снято» — 0, а не NULL. Ранг — lvl: RANK в MySQL 8
-- зарезервирован.

CREATE TABLE IF NOT EXISTS cm_chats (
  chat_id          BIGINT          NOT NULL PRIMARY KEY,
  title            VARCHAR(255)    NOT NULL DEFAULT '',
  username         VARCHAR(64)     NOT NULL DEFAULT '',
  active           TINYINT         NOT NULL DEFAULT 1,
  owner_id         BIGINT          NOT NULL DEFAULT 0,
  tg_owner_id      BIGINT          NOT NULL DEFAULT 0,
  admins           TEXT            NULL,
  admins_at        BIGINT UNSIGNED NOT NULL DEFAULT 0,
  settings         MEDIUMTEXT      NULL,
  report_chat_id   BIGINT          NOT NULL DEFAULT 0,
  rights_warned_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
  sent_window      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  sent_count       INT UNSIGNED    NOT NULL DEFAULT 0,
  added_at         BIGINT UNSIGNED NOT NULL,
  updated_at       BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cm_members (
  chat_id    BIGINT          NOT NULL,
  user_id    BIGINT          NOT NULL,
  lvl        TINYINT         NOT NULL DEFAULT 0,
  joined_at  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_seen  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  greeted_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
  left_at    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (chat_id, user_id),
  KEY idx_seen (chat_id, last_seen),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cm_users (
  user_id    BIGINT          NOT NULL PRIMARY KEY,
  username   VARCHAR(64)     NOT NULL DEFAULT '',
  name       VARCHAR(128)    NOT NULL DEFAULT '',
  updated_at BIGINT UNSIGNED NOT NULL,
  KEY idx_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Сообщения за 48 часов: чистка по человеку и фильтр флуда. Старше Telegram
-- удалять боту не даёт, поэтому cron их вычищает.
CREATE TABLE IF NOT EXISTS cm_msgs (
  chat_id    BIGINT          NOT NULL,
  message_id BIGINT          NOT NULL,
  user_id    BIGINT          NOT NULL,
  text_hash  CHAR(16)        NOT NULL DEFAULT '',
  at         BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (chat_id, message_id),
  KEY idx_user (chat_id, user_id, at),
  KEY idx_at (at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cm_punishments (
  id         INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
  chat_id    BIGINT          NOT NULL,
  user_id    BIGINT          NOT NULL,
  kind       VARCHAR(8)      NOT NULL,
  reason     VARCHAR(500)    NOT NULL DEFAULT '',
  by_id      BIGINT          NOT NULL DEFAULT 0,
  created_at BIGINT UNSIGNED NOT NULL,
  until_at   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  lifted_at  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  lifted_by  BIGINT          NOT NULL DEFAULT 0,
  KEY idx_target (chat_id, user_id, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cm_rank_log (
  id      INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
  chat_id BIGINT          NOT NULL,
  user_id BIGINT          NOT NULL,
  by_id   BIGINT          NOT NULL DEFAULT 0,
  old_lvl TINYINT         NOT NULL DEFAULT 0,
  new_lvl TINYINT         NOT NULL DEFAULT 0,
  at      BIGINT UNSIGNED NOT NULL,
  KEY idx_chat (chat_id, at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cm_reports (
  id          INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
  chat_id     BIGINT          NOT NULL,
  message_id  BIGINT          NOT NULL,
  reporter_id BIGINT          NOT NULL,
  target_id   BIGINT          NOT NULL,
  reason      VARCHAR(500)    NOT NULL DEFAULT '',
  status      VARCHAR(8)      NOT NULL DEFAULT 'open',
  handled_by  BIGINT          NOT NULL DEFAULT 0,
  created_at  BIGINT UNSIGNED NOT NULL,
  handled_at  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  KEY idx_status (chat_id, status),
  KEY idx_reporter (chat_id, reporter_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Общая база спамеров. added_by — Roblox id сотрудника сайта (admin_ids /
-- moderator_ids): вносят только они.
CREATE TABLE IF NOT EXISTS cm_spam (
  user_id  BIGINT          NOT NULL PRIMARY KEY,
  reason   VARCHAR(500)    NOT NULL DEFAULT '',
  added_by VARCHAR(20)     NOT NULL DEFAULT '',
  added_at BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cm_words (
  chat_id BIGINT      NOT NULL,
  word    VARCHAR(64) NOT NULL,
  PRIMARY KEY (chat_id, word)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cm_notes (
  chat_id    BIGINT          NOT NULL,
  name       VARCHAR(32)     NOT NULL,
  body       TEXT            NOT NULL,
  entities   TEXT            NULL,
  by_id      BIGINT          NOT NULL DEFAULT 0,
  updated_at BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (chat_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cm_timers (
  id         INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
  chat_id    BIGINT          NOT NULL,
  run_at     BIGINT UNSIGNED NOT NULL,
  body       TEXT            NOT NULL,
  by_id      BIGINT          NOT NULL DEFAULT 0,
  created_at BIGINT UNSIGNED NOT NULL,
  done_at    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  KEY idx_due (done_at, run_at),
  KEY idx_chat (chat_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Служебные ответы бота, которые удаляются через минуту (cron).
CREATE TABLE IF NOT EXISTS cm_queue (
  chat_id    BIGINT          NOT NULL,
  message_id BIGINT          NOT NULL,
  delete_at  BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (chat_id, message_id),
  KEY idx_due (delete_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- [ ] **Step 2: Таблицы для SQLite и помощники тестов**

Write `tests/cm_schema.php`:

```php
<?php
// Таблицы чат-менеджера для SQLite (тесты). Зеркалят
// docs/migrations/2026-11-07-chat-manager.sql: колонки и ключи те же, типы
// упрощены. Меняешь миграцию — меняй и здесь. tg_links — чужая таблица
// (docs/migrations/2026-09-23-telegram.sql): по ней узнаём сотрудников сайта.

function cm_sqlite_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_chats (
        chat_id INTEGER PRIMARY KEY, title TEXT NOT NULL DEFAULT '', username TEXT NOT NULL DEFAULT '',
        active INTEGER NOT NULL DEFAULT 1, owner_id INTEGER NOT NULL DEFAULT 0, tg_owner_id INTEGER NOT NULL DEFAULT 0,
        admins TEXT NULL, admins_at INTEGER NOT NULL DEFAULT 0, settings TEXT NULL,
        report_chat_id INTEGER NOT NULL DEFAULT 0, rights_warned_at INTEGER NOT NULL DEFAULT 0,
        sent_window INTEGER NOT NULL DEFAULT 0, sent_count INTEGER NOT NULL DEFAULT 0,
        added_at INTEGER NOT NULL, updated_at INTEGER NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_members (
        chat_id INTEGER NOT NULL, user_id INTEGER NOT NULL, lvl INTEGER NOT NULL DEFAULT 0,
        joined_at INTEGER NOT NULL DEFAULT 0, last_seen INTEGER NOT NULL DEFAULT 0,
        greeted_at INTEGER NOT NULL DEFAULT 0, left_at INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (chat_id, user_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_users (
        user_id INTEGER PRIMARY KEY, username TEXT NOT NULL DEFAULT '', name TEXT NOT NULL DEFAULT '',
        updated_at INTEGER NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_msgs (
        chat_id INTEGER NOT NULL, message_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
        text_hash TEXT NOT NULL DEFAULT '', at INTEGER NOT NULL, PRIMARY KEY (chat_id, message_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_punishments (
        id INTEGER PRIMARY KEY AUTOINCREMENT, chat_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
        kind TEXT NOT NULL, reason TEXT NOT NULL DEFAULT '', by_id INTEGER NOT NULL DEFAULT 0,
        created_at INTEGER NOT NULL, until_at INTEGER NOT NULL DEFAULT 0,
        lifted_at INTEGER NOT NULL DEFAULT 0, lifted_by INTEGER NOT NULL DEFAULT 0)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_rank_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT, chat_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
        by_id INTEGER NOT NULL DEFAULT 0, old_lvl INTEGER NOT NULL DEFAULT 0, new_lvl INTEGER NOT NULL DEFAULT 0,
        at INTEGER NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_reports (
        id INTEGER PRIMARY KEY AUTOINCREMENT, chat_id INTEGER NOT NULL, message_id INTEGER NOT NULL,
        reporter_id INTEGER NOT NULL, target_id INTEGER NOT NULL, reason TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT 'open', handled_by INTEGER NOT NULL DEFAULT 0,
        created_at INTEGER NOT NULL, handled_at INTEGER NOT NULL DEFAULT 0)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_spam (
        user_id INTEGER PRIMARY KEY, reason TEXT NOT NULL DEFAULT '', added_by TEXT NOT NULL DEFAULT '',
        added_at INTEGER NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_words (
        chat_id INTEGER NOT NULL, word TEXT NOT NULL, PRIMARY KEY (chat_id, word))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_notes (
        chat_id INTEGER NOT NULL, name TEXT NOT NULL, body TEXT NOT NULL, entities TEXT NULL,
        by_id INTEGER NOT NULL DEFAULT 0, updated_at INTEGER NOT NULL, PRIMARY KEY (chat_id, name))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_timers (
        id INTEGER PRIMARY KEY AUTOINCREMENT, chat_id INTEGER NOT NULL, run_at INTEGER NOT NULL,
        body TEXT NOT NULL, by_id INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL,
        done_at INTEGER NOT NULL DEFAULT 0)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_queue (
        chat_id INTEGER NOT NULL, message_id INTEGER NOT NULL, delete_at INTEGER NOT NULL,
        PRIMARY KEY (chat_id, message_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tg_links (
        user_id INTEGER PRIMARY KEY, chat_id INTEGER NOT NULL, tg_name TEXT NOT NULL DEFAULT '',
        lang TEXT NOT NULL DEFAULT 'ru', linked_at INTEGER NOT NULL)");
}
```

Write `tests/cm_helpers.php`:

```php
<?php
// Общее для tests/cm_*_test.php: база в памяти с таблицами чат-менеджера,
// запись вызовов Telegram, готовые сообщения группы и контекст обработчика.
require_once __DIR__ . '/cm_schema.php';

const CM_NOW   = 1790000000;
const CM_CHAT  = -1001234567890;
const CM_BOT   = 'MaknemyBot';
const CM_OWNER = 1;   // создатель группы в Telegram
const CM_ADMIN = 2;   // админ Telegram без ранга бота

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
```

- [ ] **Step 3: Write the failing test**

Write `tests/cm_db_test.php`:

```php
<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';

// Данные чат-менеджера: api/lib/cm/db.php и тексты api/lib/cm/texts.php.

test('без миграции модуль молчит, с ней готов', function () {
    assert_eq(false, cm_ready(test_db()));
    assert_eq(true, cm_ready(cm_test_pdo()));
});

test('выключатель в config', function () {
    assert_eq(true, cm_enabled([]));
    assert_eq(true, cm_enabled(['cm_enabled' => true]));
    assert_eq(false, cm_enabled(['cm_enabled' => false]));
});

test('по умолчанию сами работают только ссылки и спамеры', function () {
    $s = cm_settings([]);
    $on = array_keys(array_filter($s['filters']));
    sort($on);
    assert_eq(['links', 'spam'], $on);
    assert_eq(['limit' => 3, 'ttl' => 2592000], $s['warns']);
    assert_eq(true, $s['autodelete']);
});

test('настройки сливаются с умолчаниями, мусор отбрасывается', function () {
    $s = cm_settings(['filters' => ['mat' => true, 'nope' => true], 'warns' => ['limit' => 5],
                      'autodelete' => 'да', 'zzz' => 1]);
    assert_eq(true, $s['filters']['mat']);
    assert_eq(false, isset($s['filters']['nope']));
    assert_eq(5, $s['warns']['limit']);
    assert_eq(2592000, $s['warns']['ttl']);
    assert_eq(true, $s['autodelete'], 'строка вместо bool не принимается');
    assert_eq(false, isset($s['zzz']));
});

test('чат заводится один раз, название обновляется, погашенный оживает', function () {
    $pdo = cm_test_pdo();
    $c = cm_chat_ensure($pdo, ['id' => CM_CHAT, 'title' => 'Первый', 'type' => 'supergroup'], CM_NOW);
    assert_eq([CM_CHAT, 'Первый', 1, false], [$c['chat_id'], $c['title'], $c['active'], $c['private']]);
    $pdo->exec('UPDATE cm_chats SET active = 0');
    $c = cm_chat_ensure($pdo, ['id' => CM_CHAT, 'title' => 'Второй', 'username' => 'MyChat'], CM_NOW + 5);
    assert_eq(['Второй', 'mychat', 1], [$c['title'], $c['username'], $c['active']]);
    assert_eq(1, (int)$pdo->query('SELECT COUNT(*) FROM cm_chats')->fetchColumn());
    assert_eq(true, cm_private_chat(42)['private']);
});

test('настройки сохраняются и читаются', function () {
    $pdo = cm_test_pdo();
    $c = cm_chat_ensure($pdo, ['id' => CM_CHAT, 'title' => 'Т'], CM_NOW);
    $s = $c['settings'];
    $s['filters']['mat'] = true;
    $s['domains'] = ['youtube.com'];
    cm_settings_save($pdo, $c, $s, CM_NOW);
    assert_eq(true, $c['settings']['filters']['mat'], 'в памяти');
    $back = cm_chat_row($pdo, CM_CHAT);
    assert_eq(true, $back['settings']['filters']['mat']);
    assert_eq(['youtube.com'], $back['settings']['domains']);
});

test('ник ищется без учёта регистра и @, ботов не запоминаем', function () {
    $pdo = cm_test_pdo();
    cm_user_touch($pdo, ['id' => 55, 'first_name' => 'Вася', 'last_name' => 'Пупкин', 'username' => 'VasyaP'], CM_NOW);
    assert_eq(['user_id' => 55, 'name' => 'Вася Пупкин'], cm_user_find($pdo, '@vasyap'));
    assert_eq(null, cm_user_find($pdo, 'nobody'));
    assert_eq('Вася Пупкин', cm_user_name($pdo, 55));
    assert_eq('id77', cm_user_name($pdo, 77));
    cm_user_touch($pdo, ['id' => 55, 'first_name' => 'Василий', 'username' => 'vasyap'], CM_NOW + 1);
    assert_eq('Василий', cm_user_name($pdo, 55), 'имя обновляется');
    cm_user_touch($pdo, ['id' => 56, 'is_bot' => true, 'first_name' => 'Bot'], CM_NOW);
    assert_eq('id56', cm_user_name($pdo, 56));
});

test('участник заводится и обновляется по полям', function () {
    $pdo = cm_test_pdo();
    cm_member_touch($pdo, CM_CHAT, 7, CM_NOW, true);
    $m = cm_member($pdo, CM_CHAT, 7);
    assert_eq([0, CM_NOW, CM_NOW], [$m['lvl'], $m['joined_at'], $m['last_seen']]);
    cm_member_set($pdo, CM_CHAT, 7, ['lvl' => 3, 'nope' => 1]);
    cm_member_touch($pdo, CM_CHAT, 7, CM_NOW + 60);
    $m = cm_member($pdo, CM_CHAT, 7);
    assert_eq([3, CM_NOW, CM_NOW + 60], [$m['lvl'], $m['joined_at'], $m['last_seen']], 'вход не перезаписан обычным сообщением');
    assert_eq(null, cm_member($pdo, CM_CHAT, 8));
});

test('сообщения пишутся с отпечатком текста, повтор id не роняет', function () {
    $pdo = cm_test_pdo();
    cm_msg_log($pdo, CM_CHAT, 10, 7, 'Привет', CM_NOW);
    cm_msg_log($pdo, CM_CHAT, 10, 7, 'Привет', CM_NOW);
    $h = $pdo->query('SELECT text_hash FROM cm_msgs')->fetchAll(PDO::FETCH_COLUMN);
    assert_eq([cm_text_hash('привет ')], $h);
    assert_eq('', cm_text_hash('   '));
});

test('группа стала супергруппой — всё переезжает на новый id', function () {
    $pdo = cm_test_pdo();
    $c = cm_chat_ensure($pdo, ['id' => -500, 'title' => 'Старая'], CM_NOW);
    $s = $c['settings'];
    $s['filters']['mat'] = true;
    cm_settings_save($pdo, $c, $s, CM_NOW);
    cm_member_set($pdo, -500, 7, ['lvl' => 2]);
    cm_chat_ensure($pdo, ['id' => CM_CHAT, 'title' => 'Новая'], CM_NOW);
    cm_member_set($pdo, CM_CHAT, 7, ['lvl' => 0]);
    cm_migrate($pdo, -500, CM_CHAT);
    assert_eq(true, cm_chat_row($pdo, CM_CHAT)['settings']['filters']['mat']);
    assert_eq(2, cm_member($pdo, CM_CHAT, 7)['lvl']);
    assert_eq(null, cm_chat_row($pdo, -500));
    cm_migrate($pdo, -500, CM_CHAT);
    assert_eq(1, (int)$pdo->query('SELECT COUNT(*) FROM cm_chats')->fetchColumn(), 'повтор безвреден');
});

test('тексты подставляют поля', function () {
    assert_eq('⚠️ Вася: варн 1/3.', cm_t('warn_done', ['name' => 'Вася', 'n' => 1, 'limit' => 3, 'reason' => '']));
    assert_eq('нет_такого', cm_t('нет_такого'));
    assert_eq('', cm_reason_line(''));
    assert_eq("\nПричина: спам", cm_reason_line('спам'));
    assert_eq(1, preg_match('~^\d\d\.\d\d \d\d:\d\d$~', cm_date(CM_NOW)));
});

run_tests();
```

- [ ] **Step 4: Run test to verify it fails**

Run: `/c/xampp/php/php.exe tests/cm_db_test.php`
Expected: FAIL — `Failed opening required '.../public_html/api/lib/cm/cm.php'`.

- [ ] **Step 5: Загрузчик модуля**

Write `public_html/api/lib/cm/cm.php`:

```php
<?php
// Чат-менеджер — модерация групп нашим ботом (описание: docs/chat-manager.md).
// Подключается из api/tg_webhook.php и api/cm_cron.php. Части — по одной
// задаче в файле; порядок неважен для функций, важен для констант.

require_once __DIR__ . '/../chat_filter.php';

foreach (['db', 'texts'] as $cm_part) {
    require_once __DIR__ . '/' . $cm_part . '.php';
}
unset($cm_part);
```

Каждая следующая задача дописывает свои части в этот массив (итог в Task 12: `['db', 'texts', 'parse', 'send', 'queue', 'ranks', 'punish', 'words', 'filters', 'spam', 'welcome', 'reports', 'cleanup', 'notes', 'timers', 'router', 'cron']`).

- [ ] **Step 6: Данные**

Write `public_html/api/lib/cm/db.php`:

```php
<?php
// Чат-менеджер: таблицы cm_* (docs/migrations/2026-11-07-chat-manager.sql).
//
// Upsert везде «UPDATE, а если ноль строк — INSERT»: ON DUPLICATE KEY и
// ON CONFLICT непереносимы, а тесты идут на SQLite. rowCount у MySQL считает
// только изменённые строки, поэтому INSERT может упереться в ключ — это не
// ошибка, её глушим.

const CM_TZ         = 'Europe/Moscow';
const CM_MSG_KEEP   = 172800;   // 48 ч: старше Telegram не даёт боту удалять
const CM_ADMINS_TTL = 3600;     // список админов Telegram перечитывается раз в час

const CM_RANK_NAMES = ['участник', 'младший модератор', 'старший модератор',
                       'младший администратор', 'старший администратор', 'создатель'];

// Сам, без команды модератора, бот трогает только ссылки и спамеров из базы.
const CM_DEFAULTS = [
    'filters'       => ['spam' => true, 'links' => true, 'forwards' => false, 'mat' => false, 'flood' => false,
                        'caps' => false, 'stickers' => false, 'gifs' => false, 'voice' => false],
    'flood'         => [5, 10],
    'domains'       => [],
    'triggers'      => [],
    'access'        => [],
    'warns'         => ['limit' => 3, 'ttl' => 2592000],
    'welcome'       => '',
    'rules'         => ['text' => '', 'entities' => []],
    'autodelete'    => true,
    'report_code'   => null,
    'raid_greet_at' => 0,
];

function cm_enabled(array $cfg): bool {
    return !array_key_exists('cm_enabled', $cfg) || $cfg['cm_enabled'] !== false;
}

// Миграция выполняется руками: пока таблиц нет, модуль молчит.
function cm_ready(PDO $pdo): bool {
    try {
        $pdo->query('SELECT 1 FROM cm_chats LIMIT 1');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

// Сохранённые настройки поверх умолчаний. Чужие ключи и значения не того типа
// отбрасываются: настройки пишет только бот, но JSON в базе мог устареть.
function cm_settings(array $raw): array {
    $out = CM_DEFAULTS;
    foreach ($raw as $k => $v) {
        if (!array_key_exists($k, $out)) { continue; }
        if (($k === 'filters' || $k === 'warns') && is_array($v)) {
            $out[$k] = array_merge($out[$k], array_intersect_key($v, $out[$k]));
            continue;
        }
        if ($out[$k] !== null && gettype($v) !== gettype($out[$k])) { continue; }
        $out[$k] = $v;
    }
    return $out;
}

function cm_chat_decode(array $r): array {
    $ints = ['chat_id', 'active', 'owner_id', 'tg_owner_id', 'admins_at', 'report_chat_id',
             'rights_warned_at', 'sent_window', 'sent_count', 'added_at', 'updated_at'];
    foreach ($ints as $k) { $r[$k] = (int)($r[$k] ?? 0); }
    $r['title']    = (string)($r['title'] ?? '');
    $r['username'] = (string)($r['username'] ?? '');
    $s = is_string($r['settings'] ?? null) ? json_decode($r['settings'], true) : null;
    $r['settings'] = cm_settings(is_array($s) ? $s : []);
    $a = is_string($r['admins'] ?? null) ? json_decode($r['admins'], true) : null;
    $r['admins']   = is_array($a) ? array_values(array_map('intval', $a)) : [];
    $r['private']  = false;
    return $r;
}

function cm_chat_row(PDO $pdo, int $chatId): ?array {
    $st = $pdo->prepare('SELECT * FROM cm_chats WHERE chat_id = :c');
    $st->execute([':c' => $chatId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? cm_chat_decode($r) : null;
}

// Строка чата по объекту Chat из Telegram: завести, обновить название, оживить.
function cm_chat_ensure(PDO $pdo, array $tgChat, int $now): array {
    $id    = (int)($tgChat['id'] ?? 0);
    $title = mb_substr((string)($tgChat['title'] ?? ''), 0, 255);
    $user  = strtolower(mb_substr((string)($tgChat['username'] ?? ''), 0, 64));
    $row = cm_chat_row($pdo, $id);
    if ($row !== null) {
        if ($row['title'] !== $title || $row['username'] !== $user || $row['active'] !== 1) {
            $pdo->prepare('UPDATE cm_chats SET title = :t, username = :u, active = 1, updated_at = :n WHERE chat_id = :c')
                ->execute([':t' => $title, ':u' => $user, ':n' => $now, ':c' => $id]);
            $row['title'] = $title;
            $row['username'] = $user;
            $row['active'] = 1;
        }
        return $row;
    }
    try {
        $pdo->prepare('INSERT INTO cm_chats (chat_id, title, username, active, settings, added_at, updated_at)
                       VALUES (:c, :t, :u, 1, :s, :n, :n)')
            ->execute([':c' => $id, ':t' => $title, ':u' => $user, ':s' => '{}', ':n' => $now]);
    } catch (PDOException $e) {
        // параллельный апдейт уже завёл строку
    }
    return cm_chat_row($pdo, $id) ?? cm_chat_decode(['chat_id' => $id, 'title' => $title, 'username' => $user, 'active' => 1]);
}

// Личка с ботом: строки в cm_chats нет, настройки — умолчания.
function cm_private_chat(int $chatId): array {
    $c = cm_chat_decode(['chat_id' => $chatId, 'active' => 1, 'admins_at' => PHP_INT_MAX]);
    $c['private'] = true;
    return $c;
}

function cm_settings_save(PDO $pdo, array &$chat, array $settings, int $now): void {
    $chat['settings'] = cm_settings($settings);
    $pdo->prepare('UPDATE cm_chats SET settings = :s, updated_at = :n WHERE chat_id = :c')
        ->execute([':s' => json_encode($chat['settings'], JSON_UNESCAPED_UNICODE), ':n' => $now, ':c' => (int)$chat['chat_id']]);
}

// --------------------------------------------------------------------------
//  Люди
// --------------------------------------------------------------------------

function cm_display_name(array $u): string {
    $name = trim((string)($u['first_name'] ?? '') . ' ' . (string)($u['last_name'] ?? ''));
    if ($name === '' && isset($u['title'])) { $name = trim((string)$u['title']); }
    if ($name === '' && !empty($u['username'])) { $name = '@' . $u['username']; }
    if ($name === '') { $name = 'id' . (int)($u['id'] ?? 0); }
    return mb_substr($name, 0, 64);
}

// Bot API не ищет людей по нику, поэтому ник → id помним сами.
function cm_user_touch(PDO $pdo, array $from, int $now): void {
    $id = (int)($from['id'] ?? 0);
    if ($id <= 0 || !empty($from['is_bot'])) { return; }
    $p = [':id' => $id, ':u' => strtolower(mb_substr((string)($from['username'] ?? ''), 0, 64)),
          ':nm' => cm_display_name($from), ':n' => $now];
    $up = $pdo->prepare('UPDATE cm_users SET username = :u, name = :nm, updated_at = :n WHERE user_id = :id');
    $up->execute($p);
    if ($up->rowCount() > 0) { return; }
    try {
        $pdo->prepare('INSERT INTO cm_users (user_id, username, name, updated_at) VALUES (:id, :u, :nm, :n)')->execute($p);
    } catch (PDOException $e) {
        // та же секунда и те же данные: строка уже есть
    }
}

function cm_user_find(PDO $pdo, string $username): ?array {
    $st = $pdo->prepare('SELECT user_id, name FROM cm_users WHERE username = :u ORDER BY updated_at DESC LIMIT 1');
    $st->execute([':u' => strtolower(ltrim($username, '@'))]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? ['user_id' => (int)$r['user_id'], 'name' => (string)$r['name']] : null;
}

function cm_user_name(PDO $pdo, int $id): string {
    $st = $pdo->prepare('SELECT name FROM cm_users WHERE user_id = :id');
    $st->execute([':id' => $id]);
    $n = $st->fetchColumn();
    return is_string($n) && $n !== '' ? $n : 'id' . $id;
}

// --------------------------------------------------------------------------
//  Участники чата
// --------------------------------------------------------------------------

const CM_MEMBER_FIELDS = ['lvl', 'joined_at', 'last_seen', 'greeted_at', 'left_at'];

function cm_member(PDO $pdo, int $chatId, int $userId): ?array {
    $st = $pdo->prepare('SELECT * FROM cm_members WHERE chat_id = :c AND user_id = :u');
    $st->execute([':c' => $chatId, ':u' => $userId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) { return null; }
    foreach (array_merge(['chat_id', 'user_id'], CM_MEMBER_FIELDS) as $k) { $r[$k] = (int)$r[$k]; }
    return $r;
}

// Поменять поля участника; строки нет — завести с нулями в остальных.
function cm_member_set(PDO $pdo, int $chatId, int $userId, array $fields): void {
    $fields = array_intersect_key($fields, array_flip(CM_MEMBER_FIELDS));
    if (!$fields) { return; }
    $set = [];
    $p = [':c' => $chatId, ':u' => $userId];
    foreach ($fields as $k => $v) {
        $set[] = "$k = :$k";
        $p[":$k"] = (int)$v;
    }
    $up = $pdo->prepare('UPDATE cm_members SET ' . implode(', ', $set) . ' WHERE chat_id = :c AND user_id = :u');
    $up->execute($p);
    if ($up->rowCount() > 0 || cm_member($pdo, $chatId, $userId) !== null) { return; }
    $row = array_merge(array_fill_keys(CM_MEMBER_FIELDS, 0), $fields);
    $ins = [':c' => $chatId, ':u' => $userId];
    foreach ($row as $k => $v) { $ins[":$k"] = (int)$v; }
    try {
        $pdo->prepare('INSERT INTO cm_members (chat_id, user_id, lvl, joined_at, last_seen, greeted_at, left_at)
                       VALUES (:c, :u, :lvl, :joined_at, :last_seen, :greeted_at, :left_at)')->execute($ins);
    } catch (PDOException $e) {
        // параллельная вставка
    }
}

function cm_member_touch(PDO $pdo, int $chatId, int $userId, int $now, bool $joined = false): void {
    $f = ['last_seen' => $now, 'left_at' => 0];
    if ($joined) { $f['joined_at'] = $now; }
    cm_member_set($pdo, $chatId, $userId, $f);
}

// --------------------------------------------------------------------------
//  Сообщения (48 часов)
// --------------------------------------------------------------------------

function cm_text_hash(string $text): string {
    $t = trim(mb_strtolower($text));
    return $t === '' ? '' : substr(sha1($t), 0, 16);
}

function cm_msg_log(PDO $pdo, int $chatId, int $msgId, int $userId, string $text, int $now): void {
    try {
        $pdo->prepare('INSERT INTO cm_msgs (chat_id, message_id, user_id, text_hash, at) VALUES (:c, :m, :u, :h, :a)')
            ->execute([':c' => $chatId, ':m' => $msgId, ':u' => $userId, ':h' => cm_text_hash($text), ':a' => $now]);
    } catch (PDOException $e) {
        // повтор апдейта от Telegram
    }
}

// --------------------------------------------------------------------------
//  Группа стала супергруппой: у неё новый id
// --------------------------------------------------------------------------
// Telegram шлёт migrate_to_chat_id в старый чат и migrate_from_chat_id в
// новый; второй вызов ничего не находит. Строки нового id, успевшие появиться
// раньше переноса, уступают место старым.

const CM_CHAT_TABLES_PK   = ['cm_members', 'cm_msgs', 'cm_words', 'cm_notes', 'cm_queue'];
const CM_CHAT_TABLES_ROWS = ['cm_punishments', 'cm_rank_log', 'cm_reports', 'cm_timers'];

function cm_migrate(PDO $pdo, int $old, int $new): void {
    if ($old === 0 || $new === 0 || $old === $new || cm_chat_row($pdo, $old) === null) { return; }
    $pdo->beginTransaction();
    try {
        foreach (array_merge(['cm_chats'], CM_CHAT_TABLES_PK) as $t) {
            $pdo->prepare("DELETE FROM $t WHERE chat_id = :n")->execute([':n' => $new]);
        }
        foreach (array_merge(['cm_chats'], CM_CHAT_TABLES_PK, CM_CHAT_TABLES_ROWS) as $t) {
            $pdo->prepare("UPDATE $t SET chat_id = :n WHERE chat_id = :o")->execute([':n' => $new, ':o' => $old]);
        }
        $pdo->prepare('UPDATE cm_chats SET report_chat_id = :n WHERE report_chat_id = :o')->execute([':n' => $new, ':o' => $old]);
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        throw $e;
    }
}
```

- [ ] **Step 7: Тексты**

Write `public_html/api/lib/cm/texts.php`:

```php
<?php
// Все ответы чат-менеджера. Только русский: так решил владелец.
// Подстановки — {поле}, значения подставляет cm_t().

const CM_TEXTS = [
    // цель, права, общее
    'need_target'      => 'Кого? Ответьте на сообщение человека или укажите @ник или id.',
    'target_unknown'   => 'Не знаю такого ника: я его ещё не видел. Ответьте на его сообщение или укажите id.',
    'target_bot'       => 'С ботами это не работает.',
    'target_self'      => 'Себя нельзя.',
    'target_bad'       => 'Так нельзя.',
    'target_rank'      => 'Нельзя: у него ранг не ниже вашего.',
    'target_admin'     => 'Это админ Telegram — ограничить его я не могу.',
    'not_member'       => 'Его нет в чате.',
    'need_supergroup'  => 'В обычной группе Telegram так не умеет. Сделайте её супергруппой: например, откройте историю чата для новых участников.',
    'tg_failed'        => 'Telegram не принял команду.',
    'no_rights'        => 'У меня нет прав на это.',
    'need_rights'      => "Мне не хватает прав админа. Нужны:\n• Удаление сообщений\n• Блокировка пользователей",
    'need_rights_list' => "Чтобы работать, мне нужны права админа:\n• {rights}",
    'rights_ok'        => '✅ Права получены, я на посту.',
    'no_rank'          => 'Недостаточно прав: нужен ранг {need}.',
    'cmd_off'          => 'Эта команда в чате выключена.',
    'not_yours'        => 'Эта кнопка не для вас.',
    'term_bad'         => 'Срок — от 30 секунд до 366 дней или «навсегда».',
    'reason_line'      => "\nПричина: {reason}",
    'intro'            => "Привет! Я слежу за порядком в этом чате.\nСам я только удаляю ссылки и баню спамеров из общей базы. Остальное включают модераторы командами — список: помощь.",
    'btn_accept'       => 'Принять',
    'btn_confirm'      => 'Подтвердить',
    'state_on'         => 'вкл',
    'state_off'        => 'выкл',
    'list_empty'       => 'Никого.',
    'until_forever'    => 'навсегда',
    'until_date'       => 'до {date}',
    // причины, которые ставит сам бот
    'reason_warn_limit'=> 'лимит варнов',
    'reason_filter'    => 'фильтр «{filter}»',
    'reason_spam'      => 'база спамеров',
    'reason_report'    => 'жалоба',
    'reason_cleanup'   => 'чистка',
    // наказания
    'warn_done'        => '⚠️ {name}: варн {n}/{limit}.{reason}',
    'warn_limit_hit'   => '⚠️ {name}: варн {n}/{limit} — лимит: {after}{reason}',
    'done_warn'        => 'варн {n}/{limit}',
    'mute_done'        => '🔇 {name}: мут {term}.{reason}',
    'kick_done'        => '👢 {name} исключён.{reason}',
    'ban_done'         => '⛔ {name}: бан {term}.{reason}',
    'unwarn_done'      => '{name}: снят варн, осталось {n}/{limit}.',
    'unwarn_all_done'  => '{name}: сняты все варны ({n}).',
    'unwarn_none'      => 'У {name} нет активных варнов.',
    'warns_none'       => 'Активных варнов нет.',
    'warns_head'       => 'Варны (лимит {limit}):',
    'warns_user_head'  => 'Варны {name} ({n}/{limit}):',
    'unmute_done'      => '🔊 {name}: мут снят.',
    'unban_done'       => '{name} разбанен.',
    'mutes_head'       => 'В муте:',
    'bans_head'        => 'В бане:',
    'warn_limit_usage' => 'Так: «варны лимит 3» (от 1 до 20).',
    'warn_limit_done'  => 'Лимит варнов: {n}.',
    'warn_ttl_usage'   => 'Так: «варны срок 30 дней» или «варны срок навсегда».',
    'warn_ttl_done'    => 'Варн живёт: {term}.',
    // ранги и доступ
    'rank_need_level'  => 'Укажите ранг 0–4: «+модер 2».',
    'rank_too_high'    => 'Нельзя: назначать и менять можно только ранги ниже вашего.',
    'rank_same'        => '{name} уже {rank}.',
    'rank_done'        => '{name} теперь {rank} ({n}).',
    'ranks_head'       => 'Модераторы чата:',
    'ranks_empty'      => 'Модераторов пока нет.',
    'ranklog_head'     => 'Журнал рангов:',
    'ranklog_line'     => '{date} · {by}: {name} {old} → {new}',
    'ranklog_empty'    => 'Журнал пуст.',
    'owner_offer'      => '{name}, {owner} передаёт вам права создателя в этом чате. Принять?',
    'owner_done'       => '👑 Создатель чата теперь {name}.',
    'owner_tg_only'    => 'Вернуть права создателя может только владелец группы в Telegram.',
    'owner_already'    => 'Вы и так создатель.',
    'owner_restored'   => '👑 Права создателя вернулись к {name}.',
    'offer_stale'      => 'Предложение уже неактуально.',
    'access_head'      => 'Доступ к разделам (0 — всем, 1–5 — ранг, 6 — выключено):',
    'access_usage'     => 'Так: «дк бан 2». Разделы: {sections}.',
    'access_done'      => 'Раздел «{section}»: {level}.',
    'access_reset'     => 'Доступ сброшен к стандартному.',
    // фильтры и триггеры
    'filter_usage'     => 'Так: «фильтр мат вкл», «фильтр флуд 7 10». Фильтры: {names}.',
    'filter_done'      => 'Фильтр «{name}»: {state}.',
    'flood_done'       => 'Флуд: {n} сообщений за {s} с — фильтр включён.',
    'filters_head'     => 'Фильтры:',
    'filter_notice'    => '🚫 {name}: {filter} → {done}',
    'trigger_usage'    => 'Так: «триггер мат: удалить, варн». Действия: удалить, варн, мут <срок>, кик, бан <срок> — до трёх. Для «варны» варн не подходит.',
    'trigger_done'     => 'Триггер «{name}»: {actions}.',
    'trigger_reset'    => 'Триггер «{name}» по умолчанию: {actions}.',
    'triggers_head'    => 'Триггеры:',
    'trigger_default'  => ' (по умолчанию)',
    'word_usage'       => 'Так: «+слово дурак» или «+слово дурак*». От 2 символов, звёздочка только по краям.',
    'word_added'       => 'Слово «{word}» добавлено в фильтр мата.',
    'word_exists'      => 'Слово «{word}» уже в списке.',
    'word_removed'     => 'Слово «{word}» убрано.',
    'word_missing'     => 'Слова «{word}» нет в списке.',
    'words_head'       => 'Свои слова фильтра:',
    'words_empty'      => 'Своих слов нет — работает встроенный список.',
    'words_full'       => 'Список полон: не больше {max} слов.',
    'domain_usage'     => 'Так: «+домен youtube.com».',
    'domain_added'     => 'Ссылки на {domain} разрешены.',
    'domain_exists'    => '{domain} уже разрешён.',
    'domain_removed'   => '{domain} убран из разрешённых.',
    'domain_missing'   => '{domain} нет в списке.',
    'domains_head'     => 'Разрешённые домены (maknemy.com — всегда):',
    'domains_empty'    => 'Разрешён только maknemy.com.',
    'domains_full'     => 'Не больше {max} доменов.',
    'autodelete_usage' => 'Так: «автоудаление вкл» или «автоудаление выкл».',
    'autodelete_done'  => 'Автоудаление ответов бота: {state}.',
    // вход, приветствие, правила
    'welcome_usage'    => "Текст приветствия — со второй строки:\n+приветствие\nПривет, {имя}! Добро пожаловать в {чат}.",
    'welcome_done'     => 'Приветствие сохранено.',
    'welcome_removed'  => 'Приветствие выключено.',
    'welcome_none'     => 'Приветствия нет.',
    'welcome_many'     => 'новые участники',
    'greet_reset_done' => 'История приветствий сброшена.',
    'rules_usage'      => "Текст правил — со второй строки:\n+правила\n1. Без спама",
    'rules_done'       => 'Правила сохранены.',
    'rules_removed'    => 'Правила удалены.',
    'rules_none'       => 'Правил пока нет.',
    // репорты
    'report_need_reply'=> 'Жалоба — ответом на сообщение нарушителя: «репорт причина».',
    'report_staff'     => 'На модераторов жалобы здесь не принимаются.',
    'report_wait'      => 'Жалобы — не чаще раза в 5 минут.',
    'report_sent'      => 'Жалоба отправлена модераторам.',
    'report_card'      => "⚠️ Жалоба в «{chat}»\nОт: {from}\nНа: {to}\nПричина: {reason}\n{link}",
    'report_btn_del'   => '🗑 Удалить',
    'report_btn_mute'  => '🔇 Мут 1 ч',
    'report_btn_ban'   => '⛔ Бан',
    'report_btn_no'    => '✖ Отклонить',
    'report_result'    => '✅ {who}: {what}',
    'report_handled'   => 'Готово.',
    'report_closed'    => 'Жалоба уже разобрана.',
    'reports_head'     => 'Открытые жалобы:',
    'reports_empty'    => 'Открытых жалоб нет.',
    'report_code'      => "Отправьте в чате админов (я должен там быть) в течение 10 минут:\nрепорты сюда {code}",
    'report_bound'     => 'Жалобы из «{chat}» теперь приходят сюда.',
    'report_bad_code'  => 'Код не подошёл: он одноразовый, живёт 10 минут и работает только у того, кто его получил.',
    'report_home'      => 'Жалобы снова приходят в этот чат.',
    // база спамеров
    'spam_staff_only'  => 'Базу спамеров ведут только модераторы maknemy.com.',
    'spam_bad_target'  => 'Этого человека в базу внести нельзя.',
    'spam_added'       => '🚷 {name} в базе спамеров. Забанен в чатах: {n}.',
    'spam_removed'     => '{name} убран из базы спамеров.',
    'spam_missing'     => '{name} нет в базе.',
    'spam_yes'         => '🚷 {name} в базе спамеров с {date}.{reason}',
    'spam_no'          => '{name} в базе спамеров нет.',
    // чистка
    'purge_usage'      => 'Так: «чистка 50» (до 100), «чистка @ник» или «чистка» ответом на сообщение.',
    'purge_done'       => '🧹 Удалено до {n} сообщений.',
    'mass_none'        => 'Под условие никто не подходит (смотрю только тех, кого видел).',
    'mass_confirm'     => 'Подходят {n} (из тех, кого я видел). За раз исключу до {max}. Исключить?',
    'mass_confirm_deleted' => 'Проверю {n} (из тех, кого я видел) и исключу удалённые аккаунты — до {max} за раз. Начать?',
    'mass_done'        => 'Исключено: {n}.',
    'mass_more'        => 'Подходят ещё — повторите команду.',
    'inactive_usage'   => 'Так: «кик неактивных 30 дней».',
    'new_usage'        => 'Так: «кик новых 1ч».',
    // заметки
    'note_usage'       => "Так:\n+заметка имя\nтекст заметки\nИмя — до 32 букв, цифр или _.",
    'note_saved'       => 'Заметка #{name} сохранена.',
    'note_removed'     => 'Заметка #{name} удалена.',
    'note_missing'     => 'Заметки #{name} нет.',
    'notes_head'       => 'Заметки:',
    'notes_empty'      => 'Заметок нет.',
    'notes_full'       => 'Не больше {max} заметок.',
    // таймеры
    'timer_usage'      => "Так:\nтаймер через 2ч\nкоманда или текст\nили «таймер 25.12 18:00» (МСК). От минуты до года.",
    'timer_added'      => '⏰ Таймер #{id}: {date} МСК.',
    'timer_full'       => 'Не больше {max} таймеров.',
    'timers_head'      => 'Таймеры:',
    'timers_empty'     => 'Таймеров нет.',
    'timer_removed'    => 'Таймер #{id} отменён.',
    'timer_missing'    => 'Таймера #{id} нет.',
    // общие команды
    'whoami'           => 'Ваш ранг: {n} — {rank}. Варны: {warns}/{limit}.',
    'id_text'          => "ID чата: {chat}\nВаш ID: {me}",
    'id_target'        => '{name}: {id}',
    'help'             => "Команды (префикс ! . / не обязателен; цель — ответом, @ником или id):\n"
        . "👮 Ранги: +модер 2, повысить, понизить, -модер, модеры, журнал рангов\n"
        . "⚠️ Наказания: варн, мут 1ч, кик, бан 7 дней, размут, разбан, варны, муты, баны\n"
        . "🛡 Фильтры: фильтры, фильтр мат вкл, триггер мат: удалить, варн, +слово, +домен\n"
        . "👋 Вход: +приветствие, +правила, правила\n"
        . "📣 Жалобы: репорт причина (ответом), репорты\n"
        . "🧹 Чистка: чистка 50, чистка @ник, кик удалённых, кик неактивных 30 дней, кик новых 1ч\n"
        . "📝 Заметки: +заметка имя, #имя, заметки\n"
        . "⏰ Таймеры: таймер через 2ч, таймеры\n"
        . "🔑 Доступ: дк, дк бан 2",
];

function cm_t(string $key, array $vars = []): string {
    $s = CM_TEXTS[$key] ?? $key;
    $map = [];
    foreach ($vars as $k => $v) { $map['{' . $k . '}'] = (string)$v; }
    return strtr($s, $map);
}

function cm_reason_line(string $reason): string {
    return $reason === '' ? '' : cm_t('reason_line', ['reason' => $reason]);
}

function cm_date(int $ts): string {
    $d = new DateTime('@' . $ts);
    $d->setTimezone(new DateTimeZone(CM_TZ));
    return $d->format('d.m H:i');
}
```

- [ ] **Step 8: Run test to verify it passes**

Run: `/c/xampp/php/php.exe tests/cm_db_test.php`
Expected: все `ok - …`, в конце `N assertions, 0 failures`.

- [ ] **Step 9: Commit**

```bash
git add docs/migrations/2026-11-07-chat-manager.sql tests/cm_schema.php tests/cm_helpers.php tests/cm_db_test.php public_html/api/lib/cm/cm.php public_html/api/lib/cm/db.php public_html/api/lib/cm/texts.php
git commit -m "feat(cm): таблицы и данные чат-менеджера" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git show --stat HEAD
```
Expected: в `--stat` нет строк `Bin`.

---

### Task 2: Разбор команд

**Files:**
- Create: `public_html/api/lib/cm/parse.php`
- Modify: `public_html/api/lib/cm/cm.php` — в массив частей после `'texts'` добавить `'parse'`
- Test: `tests/cm_parse_test.php`

**Interfaces:**
- Consumes: `cm_user_find`, `cm_display_name` (Task 1).
- Produces:
  - `CM_COMMANDS` — `фраза => [handler, section|null, level, exact, target]`. `handler` → функция `cm_cmd_<handler>(array $ctx, array $cmd): void`. `exact` — без префикса срабатывает, только если строка ровно команда. `target` — команде нужна цель (без префикса и без цели это не команда).
  - `cm_norm(string $s): string` (нижний регистр, ё→е), `cm_phrases(): array`
  - `cm_parse(string $text, string $botName): ?array` → `['phrase','handler','section','level','exact','target','prefixed','args','body']`
  - `CM_MIN_TERM` (30), `CM_MAX_TERM` (31622400), `cm_take_term(string &$rest): ?int` — `null` нет срока, `0` навсегда, `-1` вне пределов; голое число = минуты
  - `cm_term_text(int $secs): string`, `cm_reason(string $args, string $body): string`
  - `cm_utf16_len(string $s): int`, `cm_utf16_substr(string $s, int $off, int $len): string`, `cm_body_entities(array $msg): array`
  - `cm_reply_of(array $msg): ?array`
  - `cm_target(PDO $pdo, array $msg, string &$args, ?string &$why = null): ?array` → `['id' => int, 'name' => string, 'is_bot' => bool, 'msg_id' => int]`; `$why = 'unknown'` — ник не знаком

- [ ] **Step 1: Write the failing test**

Write `tests/cm_parse_test.php`:

```php
<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';

// Разбор команд чат-менеджера: api/lib/cm/parse.php.

test('префикс необязателен, регистр и ё не важны', function () {
    foreach (['мут', '!мут', '.Мут', '/МУТ', '  !мут  '] as $t) {
        assert_eq('mute', cm_parse($t, CM_BOT)['handler'] ?? null, $t);
    }
    assert_eq(false, cm_parse('мут', CM_BOT)['prefixed']);
    assert_eq(true, cm_parse('!мут', CM_BOT)['prefixed']);
    assert_eq('kick_deleted', cm_parse('кик удалённых', CM_BOT)['handler']);
});

test('слово команды целиком: муты — не мут, мутный — не команда', function () {
    assert_eq('mutes', cm_parse('муты', CM_BOT)['handler']);
    assert_eq(null, cm_parse('мутный тип', CM_BOT));
    assert_eq('warn_limit', cm_parse('варны лимит 5', CM_BOT)['handler']);
    assert_eq('5', cm_parse('варны лимит 5', CM_BOT)['args']);
    assert_eq('trigger_set', cm_parse('триггер мат: удалить', CM_BOT)['handler']);
    assert_eq('мат: удалить', cm_parse('триггер мат: удалить', CM_BOT)['args']);
    assert_eq(null, cm_parse('', CM_BOT));
    assert_eq(null, cm_parse("\nмут", CM_BOT));
    assert_eq(null, cm_parse('/start abc', CM_BOT));
});

test('/команда@бот — только наш бот, @ник в аргументах не трогаем', function () {
    assert_eq('ban', cm_parse('/бан@MaknemyBot 1д', CM_BOT)['handler']);
    assert_eq('1д', cm_parse('/бан@maknemybot 1д', CM_BOT)['args']);
    assert_eq(null, cm_parse('/бан@OtherBot', CM_BOT));
    assert_eq('@vasya 1ч', cm_parse('мут @vasya 1ч', CM_BOT)['args']);
});

test('у команды есть флаги для роутера', function () {
    $c = cm_parse('правила', CM_BOT);
    assert_eq([null, 0, true, false], [$c['section'], $c['level'], $c['exact'], $c['target']]);
    $c = cm_parse('бан', CM_BOT);
    assert_eq(['бан', 3, false, true], [$c['section'], $c['level'], $c['exact'], $c['target']]);
});

test('тело команды — со второй строки как есть', function () {
    $c = cm_parse("+правила\n1. Не спамить\n2. Не ругаться", CM_BOT);
    assert_eq('rules_set', $c['handler']);
    assert_eq('', $c['args']);
    assert_eq("1. Не спамить\n2. Не ругаться", $c['body']);
});

test('сроки: единицы, голое число — минуты, «навсегда»', function () {
    $cases = ['10м' => 600, '10 минут' => 600, '2ч' => 7200, '2 часа' => 7200, '3д' => 259200,
              '3 дня' => 259200, 'неделя' => 604800, '2 недели' => 1209600, 'месяц' => 2592000,
              'год' => 31536000, 'навсегда' => 0, '30с' => 30, '15' => 900, 'час' => 3600];
    foreach ($cases as $in => $want) {
        $r = $in . ' флуд';
        assert_eq($want, cm_take_term($r), $in);
        assert_eq('флуд', $r, "остаток после «$in»");
    }
    foreach (['10с', '0м', '400 дней', '2 года'] as $bad) {
        $r = $bad;
        assert_eq(-1, cm_take_term($r), $bad);
    }
    foreach (['спам', 'м', 'за флуд', ''] as $none) {
        $r = $none;
        assert_eq(null, cm_take_term($r), "«$none»");
        assert_eq($none, $r, 'без срока строка не меняется');
    }
});

test('срок словами', function () {
    assert_eq('навсегда', cm_term_text(0));
    assert_eq('7 дн.', cm_term_text(604800));
    assert_eq('2 ч', cm_term_text(7200));
    assert_eq('10 мин', cm_term_text(600));
    assert_eq('45 с', cm_term_text(45));
});

test('цель: ответ, @ник, ссылка, id', function () {
    $pdo = cm_test_pdo();
    cm_person($pdo, 55, 'vasya', 'Вася');
    $reply = ['message_id' => 7, 'from' => ['id' => 66, 'first_name' => 'Петя']];
    $a = '1ч спам';
    $t = cm_target($pdo, cm_gmsg(1, 'мут 1ч спам', ['reply_to_message' => $reply]), $a);
    assert_eq([66, 'Петя', 7, false], [$t['id'], $t['name'], $t['msg_id'], $t['is_bot']]);
    assert_eq('1ч спам', $a, 'ответ аргументы не ест');
    foreach (['@vasya 1ч', 't.me/vasya 1ч', 'https://t.me/Vasya 1ч'] as $in) {
        $a = $in;
        $t = cm_target($pdo, cm_gmsg(1, 'мут ' . $in), $a);
        assert_eq(55, $t['id'] ?? null, $in);
        assert_eq('1ч', $a, $in);
    }
    $a = '123456789 1ч';
    $t = cm_target($pdo, cm_gmsg(1, 'x'), $a);
    assert_eq([123456789, 'id123456789'], [$t['id'], $t['name']]);
    assert_eq('1ч', $a);
});

test('незнакомый ник и отсутствие цели', function () {
    $pdo = cm_test_pdo();
    $a = '@ghost 1ч';
    $why = null;
    assert_eq(null, cm_target($pdo, cm_gmsg(1, 'мут @ghost 1ч'), $a, $why));
    assert_eq('unknown', $why);
    assert_eq('@ghost 1ч', $a, 'неудача аргументы не портит');
    $a = '1ч';
    $why = null;
    assert_eq(null, cm_target($pdo, cm_gmsg(1, 'мут 1ч'), $a, $why));
    assert_eq(null, $why);
});

test('упоминание без ника и ранг перед целью', function () {
    $pdo = cm_test_pdo();
    $msg = cm_gmsg(1, 'мут Иван 1ч', ['entities' => [
        ['type' => 'text_mention', 'offset' => 4, 'length' => 4, 'user' => ['id' => 77, 'first_name' => 'Иван']],
    ]]);
    $a = 'Иван 1ч';
    $t = cm_target($pdo, $msg, $a);
    assert_eq(77, $t['id']);
    assert_eq('1ч', $a);
    cm_person($pdo, 55, 'vasya');
    $a = '2 @vasya';
    $t = cm_target($pdo, cm_gmsg(1, '+модер 2 @vasya'), $a);
    assert_eq(55, $t['id']);
    assert_eq('2', $a, 'ранг остаётся в аргументах');
});

test('ответ на корень темы форума — не ответ', function () {
    $msg = cm_gmsg(1, 'мут', ['is_topic_message' => true, 'message_thread_id' => 50,
        'reply_to_message' => ['message_id' => 50, 'from' => ['id' => 9], 'forum_topic_created' => ['name' => 'x']]]);
    assert_eq(null, cm_reply_of($msg));
    $msg['reply_to_message'] = ['message_id' => 60, 'from' => ['id' => 9]];
    assert_eq(60, cm_reply_of($msg)['message_id']);
});

test('причина: остаток строки и тело, до 500 знаков', function () {
    assert_eq("спам\nи реклама", cm_reason(' спам ', "и реклама\n"));
    assert_eq('спам', cm_reason('спам', ''));
    assert_eq(500, mb_strlen(cm_reason(str_repeat('я', 600), '')));
});

test('разметка тела сдвигается на первую строку', function () {
    $msg = cm_gmsg(1, "+заметка правила\nЖирный текст", ['entities' => [
        ['type' => 'bold', 'offset' => 0, 'length' => 8],
        ['type' => 'bold', 'offset' => 17, 'length' => 6],
    ]]);
    assert_eq([['type' => 'bold', 'offset' => 0, 'length' => 6]], cm_body_entities($msg));
    assert_eq(2, cm_utf16_len('😀'));
    assert_eq('мир', cm_utf16_substr('😀 мир', 3, 3));
    assert_eq('', cm_utf16_substr('мир', 10, 3));
});

run_tests();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/c/xampp/php/php.exe tests/cm_parse_test.php`
Expected: FAIL — `Call to undefined function cm_parse()`.

- [ ] **Step 3: Write minimal implementation**

Write `public_html/api/lib/cm/parse.php`:

```php
<?php
// Разбор команд чат-менеджера: префикс, слово команды, цель, срок, причина.

// фраза => [обработчик, раздел для «дк» или null, ранг по умолчанию,
//           exact — без префикса только ровно команда,
//           target — нужна цель: без префикса и без цели это не команда].
// Фразы в нижнем регистре и с «е» вместо «ё» (cm_norm).
const CM_COMMANDS = [
    '+модер'             => ['rank_set', 'ранги', 4, false, true],
    'повысить'           => ['rank_up', 'ранги', 4, false, true],
    'понизить'           => ['rank_down', 'ранги', 4, false, true],
    '-модер'             => ['rank_clear', 'ранги', 4, false, true],
    'снять модера'       => ['rank_clear', 'ранги', 4, false, true],
    'модеры'             => ['rank_list', null, 0, true, false],
    'журнал рангов'      => ['rank_log', 'ранги', 4, false, false],
    'передать создателя' => ['owner_pass', null, 5, false, true],
    'вернуть создателя'  => ['owner_restore', null, 0, true, false],
    'дк'                 => ['access', null, 5, false, false],
    'варн'               => ['warn', 'варны', 1, false, true],
    '-варн'              => ['unwarn', 'варны', 1, false, true],
    'варны'              => ['warns', 'варны', 1, false, false],
    'снять варны'        => ['unwarn_all', 'варны', 1, false, true],
    'варны лимит'        => ['warn_limit', 'триггеры', 3, false, false],
    'варны срок'         => ['warn_ttl', 'триггеры', 3, false, false],
    'варны наказание'    => ['warn_action', 'триггеры', 3, false, false],
    'мут'                => ['mute', 'мут', 1, false, true],
    'размут'             => ['unmute', 'мут', 1, false, true],
    'муты'               => ['mutes', 'мут', 1, false, false],
    'кик'                => ['kick', 'кик', 2, false, true],
    'бан'                => ['ban', 'бан', 3, false, true],
    'разбан'             => ['unban', 'бан', 3, false, true],
    'баны'               => ['bans', 'бан', 3, false, false],
    'фильтр'             => ['filter_set', 'фильтры', 3, false, false],
    'фильтры'            => ['filter_list', null, 0, true, false],
    '+слово'             => ['word_add', 'фильтры', 3, false, false],
    '-слово'             => ['word_del', 'фильтры', 3, false, false],
    'слова'              => ['word_list', 'фильтры', 3, false, false],
    '+домен'             => ['domain_add', 'фильтры', 3, false, false],
    '-домен'             => ['domain_del', 'фильтры', 3, false, false],
    'домены'             => ['domain_list', 'фильтры', 3, false, false],
    'автоудаление'       => ['autodelete', 'фильтры', 3, false, false],
    'триггер'            => ['trigger_set', 'триггеры', 3, false, false],
    '-триггер'           => ['trigger_clear', 'триггеры', 3, false, false],
    'триггеры'           => ['trigger_list', 'триггеры', 3, false, false],
    '+приветствие'       => ['welcome_set', 'приветствие', 3, false, false],
    '-приветствие'       => ['welcome_del', 'приветствие', 3, false, false],
    'приветствие'        => ['welcome_show', 'приветствие', 3, false, false],
    'сброс приветствий'  => ['greet_reset', 'приветствие', 3, false, false],
    '+правила'           => ['rules_set', 'правила', 3, false, false],
    '-правила'           => ['rules_del', 'правила', 3, false, false],
    'правила'            => ['rules_show', null, 0, true, false],
    'репорт'             => ['report', null, 0, false, false],
    'репорты'            => ['report_list', 'репорты', 2, false, false],
    'репорты в чат'      => ['report_code', 'репорты', 4, false, false],
    'репорты сюда'       => ['report_bind', null, 0, false, false],
    'репорты здесь'      => ['report_home', 'репорты', 4, false, false],
    '+спамер'            => ['spam_add', null, 0, false, true],
    '-спамер'            => ['spam_del', null, 0, false, true],
    'проверить'          => ['spam_check', null, 0, false, true],
    'чистка'             => ['purge', 'чистка', 3, false, false],
    'кик удаленных'      => ['kick_deleted', 'чистка', 3, false, false],
    'кик неактивных'     => ['kick_inactive', 'чистка', 3, false, false],
    'кик новых'          => ['kick_new', 'чистка', 3, false, false],
    '+заметка'           => ['note_add', 'заметки', 2, false, false],
    '-заметка'           => ['note_del', 'заметки', 2, false, false],
    'заметки'            => ['note_list', null, 0, true, false],
    'таймер'             => ['timer_add', 'таймеры', 1, false, false],
    'таймеры'            => ['timer_list', 'таймеры', 1, false, false],
    '-таймер'            => ['timer_del', 'таймеры', 1, false, false],
    'помощь'             => ['help', null, 0, true, false],
    'команды'            => ['help', null, 0, true, false],
    'кто я'              => ['whoami', null, 0, true, false],
    'id'                 => ['id', null, 0, true, false],
    'ид'                 => ['id', null, 0, true, false],
];

const CM_PREFIXES = ['!', '.', '/'];

function cm_norm(string $s): string {
    return str_replace('ё', 'е', mb_strtolower($s));
}

// Длинные фразы раньше коротких: «варны лимит» прежде «варны», «кик новых»
// прежде «кик».
function cm_phrases(): array {
    static $p = null;
    if ($p === null) {
        $p = array_keys(CM_COMMANDS);
        usort($p, function ($a, $b) { return strlen($b) - strlen($a); });
    }
    return $p;
}

/**
 * Команда из текста сообщения или null. args — остаток первой строки
 * (исходный регистр), body — всё со второй строки как есть: от него
 * отсчитывается разметка заметок и правил (cm_body_entities).
 */
function cm_parse(string $text, string $botName): ?array {
    $nl    = strpos($text, "\n");
    $first = $nl === false ? $text : substr($text, 0, $nl);
    $body  = $nl === false ? '' : substr($text, $nl + 1);
    $line  = trim($first);
    if ($line === '') { return null; }
    $prefixed = in_array($line[0], CM_PREFIXES, true);
    if ($prefixed) { $line = ltrim(substr($line, 1)); }
    // /бан@ИмяБота — чужому боту не отвечаем.
    if ($prefixed && preg_match('~^([^\s@]+)@(\w+)(.*)\z~su', $line, $m)) {
        if ($botName === '' || strcasecmp($m[2], $botName) !== 0) { return null; }
        $line = $m[1] . $m[3];
    }
    // Длина в байтах у $norm и $line в начале строки совпадает: в фразах
    // только кириллица, латиница и «+-», а их нижний регистр той же длины.
    $norm = cm_norm($line);
    foreach (cm_phrases() as $phrase) {
        $len = strlen($phrase);
        if (strncmp($norm, $phrase, $len) !== 0) { continue; }
        $tail = substr($norm, $len);
        if ($tail !== '' && !preg_match('~^[\s:]~u', $tail)) { continue; }
        [$handler, $section, $level, $exact, $target] = CM_COMMANDS[$phrase];
        return ['phrase' => $phrase, 'handler' => $handler, 'section' => $section, 'level' => $level,
                'exact' => $exact, 'target' => $target, 'prefixed' => $prefixed,
                'args' => trim(substr($line, $len)), 'body' => $body];
    }
    return null;
}

// --------------------------------------------------------------------------
//  Сроки
// --------------------------------------------------------------------------

// [регулярка единицы, секунд]. Внутри — длинные формы раньше коротких.
const CM_UNITS = [
    ['секунд[аы]?|секунду|сек|с', 1],
    ['минут[аыу]?|мин|м', 60],
    ['часа|часов|час|ч', 3600],
    ['дней|дня|день|дн|д', 86400],
    ['недел[июья]|нед|н', 604800],
    ['месяцев|месяца|месяц|мес', 2592000],
    ['года|год|лет|г', 31536000],
];
// Telegram считает срок меньше 30 секунд или больше 366 дней бессрочным.
const CM_MIN_TERM = 30;
const CM_MAX_TERM = 31622400;

/**
 * Срок в начале строки; съедается из $rest. null — срока нет, 0 — «навсегда»,
 * -1 — срок есть, но вне пределов Telegram. Голое число — минуты. Единица без
 * числа — только словом от трёх букв («час», «неделя»), иначе «м» в начале
 * причины считалось бы минутой.
 */
function cm_take_term(string &$rest): ?int {
    $s = ltrim($rest);
    $n = cm_norm($s);
    if (preg_match('~^навсегда(?![\p{L}\p{N}])~u', $n, $m)) {
        $rest = ltrim(substr($s, strlen($m[0])));
        return 0;
    }
    foreach (CM_UNITS as $u) {
        if (!preg_match('~^(\d{1,4})?\s*(?:' . $u[0] . ')(?![\p{L}\p{N}])~u', $n, $m)) { continue; }
        $hasNum = isset($m[1]) && $m[1] !== '';
        if (!$hasNum && mb_strlen($m[0]) < 3) { continue; }
        $rest = ltrim(substr($s, strlen($m[0])));
        $t = ($hasNum ? (int)$m[1] : 1) * $u[1];
        return ($t < CM_MIN_TERM || $t > CM_MAX_TERM) ? -1 : $t;
    }
    if (preg_match('~^(\d{1,4})(?![\p{L}\p{N}])~u', $n, $m)) {
        $rest = ltrim(substr($s, strlen($m[0])));
        $t = (int)$m[1] * 60;
        return ($t < CM_MIN_TERM || $t > CM_MAX_TERM) ? -1 : $t;
    }
    return null;
}

function cm_term_text(int $secs): string {
    if ($secs <= 0) { return 'навсегда'; }
    if ($secs % 86400 === 0) { return intdiv($secs, 86400) . ' дн.'; }
    if ($secs % 3600 === 0) { return intdiv($secs, 3600) . ' ч'; }
    if ($secs % 60 === 0) { return intdiv($secs, 60) . ' мин'; }
    return $secs . ' с';
}

function cm_reason(string $args, string $body): string {
    return mb_substr(trim(trim($args) . "\n" . trim($body)), 0, 500);
}

// --------------------------------------------------------------------------
//  Разметка: Telegram считает смещения в единицах UTF-16
// --------------------------------------------------------------------------

function cm_utf16_len(string $s): int {
    return intdiv(strlen((string)mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')), 2);
}

function cm_utf16_substr(string $s, int $off, int $len): string {
    $u = (string)mb_convert_encoding($s, 'UTF-16LE', 'UTF-8');
    $part = (string)substr($u, $off * 2, $len * 2);
    return $part === '' ? '' : (string)mb_convert_encoding($part, 'UTF-8', 'UTF-16LE');
}

// Разметка текста со второй строки, со смещениями от начала тела.
function cm_body_entities(array $msg): array {
    $text = (string)($msg['text'] ?? '');
    $nl = strpos($text, "\n");
    if ($nl === false) { return []; }
    $shift = cm_utf16_len(substr($text, 0, $nl + 1));
    $out = [];
    foreach ((array)($msg['entities'] ?? []) as $e) {
        if (!is_array($e) || (int)($e['offset'] ?? 0) < $shift) { continue; }
        $e['offset'] = (int)$e['offset'] - $shift;
        $out[] = $e;
    }
    return $out;
}

// --------------------------------------------------------------------------
//  Цель
// --------------------------------------------------------------------------

// Сообщение, на которое ответили. В теме форума каждое сообщение формально
// отвечает на корень темы — это не ответ.
function cm_reply_of(array $msg): ?array {
    $r = $msg['reply_to_message'] ?? null;
    if (!is_array($r) || isset($r['forum_topic_created'])) { return null; }
    if (!empty($msg['is_topic_message']) && (int)($r['message_id'] ?? 0) === (int)($msg['message_thread_id'] ?? -1)) {
        return null;
    }
    return $r;
}

/**
 * Цель команды по старшинству: ответ → упоминание без ника → @ник / t.me/ник →
 * id. Найденная цель вырезается из $args (кроме ответа). Перед целью может
 * стоять ранг («+модер 2 @ник») — он остаётся в $args. null — цели нет; если
 * ник незнаком, $why = 'unknown'. При неудаче $args не меняется.
 */
function cm_target(PDO $pdo, array $msg, string &$args, ?string &$why = null): ?array {
    $why = null;
    $reply = cm_reply_of($msg);
    if ($reply !== null && is_array($reply['from'] ?? null)) {
        $f = $reply['from'];
        return ['id' => (int)($f['id'] ?? 0), 'name' => cm_display_name($f), 'is_bot' => !empty($f['is_bot']),
                'msg_id' => (int)($reply['message_id'] ?? 0)];
    }
    $text = (string)($msg['text'] ?? '');
    foreach ((array)($msg['entities'] ?? []) as $e) {
        if (!is_array($e) || ($e['type'] ?? '') !== 'text_mention' || !is_array($e['user'] ?? null)) { continue; }
        $label = cm_utf16_substr($text, (int)($e['offset'] ?? 0), (int)($e['length'] ?? 0));
        $pos = $label === '' ? false : strpos($args, $label);
        if ($pos !== false) { $args = trim(substr($args, 0, $pos) . substr($args, $pos + strlen($label))); }
        $u = $e['user'];
        return ['id' => (int)($u['id'] ?? 0), 'name' => cm_display_name($u), 'is_bot' => !empty($u['is_bot']), 'msg_id' => 0];
    }
    $orig = $args;
    $lead = '';
    if (preg_match('~^([0-4])\s+~', $args, $lm)) {
        $lead = $lm[1];
        $args = substr($args, strlen($lm[0]));
    }
    $found = null;
    if (preg_match('~^(?:@|(?:https?://)?t\.me/)([A-Za-z]\w{3,31})(?!\w)~iu', $args, $m)) {
        $u = cm_user_find($pdo, $m[1]);
        if ($u === null) {
            $why = 'unknown';
        } else {
            $found = ['id' => $u['user_id'], 'name' => $u['name'], 'is_bot' => false, 'msg_id' => 0];
            $args = ltrim(substr($args, strlen($m[0])));
        }
    } elseif (preg_match('~^(\d{5,15})(?!\d)~', $args, $m)) {
        $id = (int)$m[1];
        $found = ['id' => $id, 'name' => cm_user_name($pdo, $id), 'is_bot' => false, 'msg_id' => 0];
        $args = ltrim(substr($args, strlen($m[0])));
    }
    if ($found === null) {
        $args = $orig;
        return null;
    }
    $args = trim($lead . ' ' . $args);
    return $found;
}
```

Edit `public_html/api/lib/cm/cm.php`: `foreach (['db', 'texts'] as $cm_part)` → `foreach (['db', 'texts', 'parse'] as $cm_part)`.

- [ ] **Step 4: Run test to verify it passes**

Run: `/c/xampp/php/php.exe tests/cm_parse_test.php`
Expected: `0 failures`. Ещё раз `/c/xampp/php/php.exe tests/cm_db_test.php` — `0 failures`.

- [ ] **Step 5: Commit**

```bash
git add public_html/api/lib/cm/parse.php public_html/api/lib/cm/cm.php tests/cm_parse_test.php
git commit -m "feat(cm): разбор команд, сроков и цели" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git show --stat HEAD
```

---
### Task 3: Контекст, отправка, очередь, ранги

**Files:**
- Create: `public_html/api/lib/cm/send.php`, `public_html/api/lib/cm/queue.php`, `public_html/api/lib/cm/ranks.php` (здесь — только ядро рангов; команды рангов — Task 4)
- Modify: `public_html/api/lib/cm/cm.php` — массив частей: `['db', 'texts', 'parse', 'send', 'queue', 'ranks']`
- Test: `tests/cm_core_test.php`

**Interfaces:**
- Consumes: Task 1–2.
- Produces:
  - `$ctx` = `['pdo','cfg','send','chat','msg','now','actor' (int),'rank' (int),'edited' (bool),'timer' (bool),'bot' (string),'thread_id' (int)]`
  - `cm_ctx(PDO $pdo, array $cfg, callable $send, array $chat, array $msg, int $now): array`
  - `cm_ok(?array $res): bool`, `cm_tg_error(?array $res): string` — `''` или ключ: `target_admin`, `no_rights`, `need_supergroup`, `not_member`, `tg_failed`
  - `CM_BUDGET` (15), `CM_TEMP_SECS` (60), `cm_budget_take(PDO $pdo, int $chatId, int $now): bool`
  - `cm_say(array $ctx, int $chatId, string $text, array $o = []): int` — `$o`: `reply_to`, `buttons` (inline_keyboard), `entities`, `temp`; возвращает message_id или 0
  - `cm_reply(array $ctx, string $text, bool $temp = true, array $o = []): int` — в чат контекста ответом на `$ctx['msg']`
  - `cm_reply_err(array $ctx, string $err): void`, `cm_rights_warn(array $ctx, bool $force = false): void`
  - `cm_queue_add(PDO $pdo, int $chatId, int $msgId, int $at): void`, `cm_delete_many(callable $send, int $chatId, array $ids): bool`, `cm_queue_tick(PDO $pdo, callable $send, int $now): int`
  - `CM_SECTIONS`, `cm_admins_refresh(array &$ctx, bool $force = false): void`, `cm_rank(array $ctx, int $userId): int`, `cm_is_tg_admin(array $ctx, int $userId): bool`, `cm_exempt(array $ctx, int $userId): bool`, `cm_can_touch(array $ctx, array $t): string`, `cm_section_default(string $section): int`, `cm_need_level(array $chat, ?string $section, int $default): int`, `cm_rank_store(PDO $pdo, int $chatId, int $userId, int $old, int $new, int $byId, int $now): void`, `cm_need_target(array $ctx, string &$args): ?array`

- [ ] **Step 1: Write the failing test**

Write `tests/cm_core_test.php`:

```php
<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';

// Ядро чат-менеджера: отправка (send.php), очередь удаления (queue.php),
// ранги и доступ (ranks.php).

test('ошибки Telegram по смыслу', function () {
    assert_eq('', cm_tg_error(['ok' => true]));
    assert_eq('target_admin', cm_tg_error(['ok' => false, 'description' => 'Bad Request: user is an administrator of the chat']));
    assert_eq('target_admin', cm_tg_error(['ok' => false, 'description' => "Bad Request: can't remove chat owner"]));
    assert_eq('no_rights', cm_tg_error(['ok' => false, 'description' => 'Bad Request: not enough rights to restrict/unrestrict chat member']));
    assert_eq('no_rights', cm_tg_error(['ok' => false, 'description' => 'Bad Request: CHAT_ADMIN_REQUIRED']));
    assert_eq('need_supergroup', cm_tg_error(['ok' => false, 'description' => 'Bad Request: method is available only for supergroups']));
    assert_eq('not_member', cm_tg_error(['ok' => false, 'description' => 'Bad Request: user not found']));
    assert_eq('tg_failed', cm_tg_error(null));
});

test('бюджет: не больше 15 сообщений в минуту на группу', function () {
    $pdo = cm_test_pdo();
    cm_chat_ensure($pdo, ['id' => CM_CHAT], CM_NOW);
    $ok = 0;
    for ($i = 0; $i < 20; $i++) { if (cm_budget_take($pdo, CM_CHAT, CM_NOW)) { $ok++; } }
    assert_eq(15, $ok);
    assert_eq(true, cm_budget_take($pdo, CM_CHAT, CM_NOW + 60), 'новая минута');
    assert_eq(true, cm_budget_take($pdo, 12345, CM_NOW), 'личка без бюджета');
});

test('ответ уходит ответом в тот же чат, временный — в очередь на удаление', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, 7, 'кто я');
    $id = cm_reply($ctx, 'привет');
    $sent = cm_calls($log, 'sendMessage')[0][1];
    assert_eq([CM_CHAT, 'привет', $ctx['msg']['message_id'], true],
        [$sent['chat_id'], $sent['text'], $sent['reply_parameters']['message_id'], $sent['reply_parameters']['allow_sending_without_reply']]);
    $q = $pdo->query('SELECT chat_id, message_id, delete_at FROM cm_queue')->fetch(PDO::FETCH_NUM);
    assert_eq([CM_CHAT, $id, CM_NOW + 60], array_map('intval', $q));
    cm_reply($ctx, 'постоянный', false);
    assert_eq(1, (int)$pdo->query('SELECT COUNT(*) FROM cm_queue')->fetchColumn());
});

test('без автоудаления временный ответ остаётся', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, 7);
    $s = $ctx['chat']['settings'];
    $s['autodelete'] = false;
    cm_settings_save($pdo, $ctx['chat'], $s, CM_NOW);
    cm_reply($ctx, 'привет');
    assert_eq(0, (int)$pdo->query('SELECT COUNT(*) FROM cm_queue')->fetchColumn());
});

test('исчерпан бюджет — бот молчит', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, 7);
    $pdo->prepare('UPDATE cm_chats SET sent_window = :w, sent_count = 15')->execute([':w' => intdiv(CM_NOW, 60)]);
    assert_eq(0, cm_say($ctx, CM_CHAT, 'тишина'));
    assert_eq(0, count(cm_calls($log, 'sendMessage')));
});

test('о правах — не чаще раза в сутки, по команде — сразу', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, 7);
    cm_rights_warn($ctx);
    cm_rights_warn($ctx);
    assert_eq(1, count(cm_calls($log, 'sendMessage')));
    cm_rights_warn($ctx, true);
    assert_eq(2, count(cm_calls($log, 'sendMessage')));
    $ctx['now'] = CM_NOW + 86401;
    cm_rights_warn($ctx);
    assert_eq(3, count(cm_calls($log, 'sendMessage')));
});

test('создатель — ранг 5, админ Telegram — 0, но фильтры его не трогают', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_lvl($pdo, 10, 2);
    $ctx = cm_ctx_for($pdo, $log, 10);
    assert_eq([5, 0, 2, 0], [cm_rank($ctx, CM_OWNER), cm_rank($ctx, CM_ADMIN), cm_rank($ctx, 10), cm_rank($ctx, 11)]);
    assert_eq([true, true, false], [cm_exempt($ctx, CM_ADMIN), cm_exempt($ctx, 10), cm_exempt($ctx, 11)]);
    assert_eq('Owner', cm_user_name($pdo, CM_OWNER), 'админов запоминаем по именам');
    assert_eq(1, count(cm_calls($log, 'getChatAdministrators')));
    cm_admins_refresh($ctx);
    assert_eq(1, count(cm_calls($log, 'getChatAdministrators')), 'кеш на час');
    $ctx['now'] += 3601;
    cm_admins_refresh($ctx);
    assert_eq(2, count(cm_calls($log, 'getChatAdministrators')));
});

test('Telegram не отдал админов — повтор через час, а не на каждом сообщении', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, 7, '', [], ['getChatAdministrators' => ['ok' => false]]);
    cm_admins_refresh($ctx);
    assert_eq(1, count(cm_calls($log, 'getChatAdministrators')));
});

test('кого можно трогать', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_lvl($pdo, 10, 2);
    cm_lvl($pdo, 11, 2);
    cm_lvl($pdo, 12, 1);
    $ctx = cm_ctx_for($pdo, $log, 10);
    assert_eq('', cm_can_touch($ctx, ['id' => 12, 'is_bot' => false]));
    assert_eq('target_rank', cm_can_touch($ctx, ['id' => 11, 'is_bot' => false]));
    assert_eq('target_self', cm_can_touch($ctx, ['id' => 10, 'is_bot' => false]));
    assert_eq('target_admin', cm_can_touch($ctx, ['id' => CM_ADMIN, 'is_bot' => false]));
    assert_eq('target_bot', cm_can_touch($ctx, ['id' => 99, 'is_bot' => true]));
});

test('уровень доступа: раздел из настроек, иначе по умолчанию', function () {
    $chat = ['settings' => cm_settings(['access' => ['бан' => 2, 'мут' => 9]])];
    assert_eq(2, cm_need_level($chat, 'бан', 3));
    assert_eq(1, cm_need_level($chat, 'мут', 1), 'мусор игнорируется');
    assert_eq(5, cm_need_level($chat, null, 5));
    assert_eq(4, cm_section_default('ранги'));
    assert_eq(2, cm_section_default('репорты'));
});

test('без цели — подсказка', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, 10, 'мут 1ч');
    $args = '1ч';
    assert_eq(null, cm_need_target($ctx, $args));
    assert_eq(cm_t('need_target'), cm_last_text($log));
    $args = '@ghost';
    assert_eq(null, cm_need_target($ctx, $args));
    assert_eq(cm_t('target_unknown'), cm_last_text($log));
});

test('очередь удаляет пачками по чатам и чистит старые сообщения', function () {
    $pdo = cm_test_pdo();
    $log = [];
    foreach ([1, 2, 3] as $m) { cm_queue_add($pdo, CM_CHAT, $m, CM_NOW - 1); }
    cm_queue_add($pdo, CM_CHAT, 4, CM_NOW + 100);
    cm_msg_log($pdo, CM_CHAT, 50, 7, 'старое', CM_NOW - CM_MSG_KEEP - 1);
    cm_msg_log($pdo, CM_CHAT, 51, 7, 'свежее', CM_NOW);
    assert_eq(3, cm_queue_tick($pdo, cm_rec($log), CM_NOW));
    $del = cm_calls($log, 'deleteMessages');
    assert_eq(1, count($del));
    assert_eq([CM_CHAT, [1, 2, 3]], [$del[0][1]['chat_id'], $del[0][1]['message_ids']]);
    assert_eq(1, (int)$pdo->query('SELECT COUNT(*) FROM cm_queue')->fetchColumn());
    assert_eq([51], array_map('intval', $pdo->query('SELECT message_id FROM cm_msgs')->fetchAll(PDO::FETCH_COLUMN)));
});

test('пакетное удаление режет по 100 и сообщает об отказе в правах', function () {
    $log = [];
    assert_eq(true, cm_delete_many(cm_rec($log), CM_CHAT, range(1, 150)));
    assert_eq([100, 50], array_map(function ($c) { return count($c[1]['message_ids']); }, $log));
    $log = [];
    $deny = ['deleteMessages' => ['ok' => false, 'description' => 'Bad Request: CHAT_ADMIN_REQUIRED']];
    assert_eq(false, cm_delete_many(cm_rec($log, $deny), CM_CHAT, [1]));
});

run_tests();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/c/xampp/php/php.exe tests/cm_core_test.php`
Expected: FAIL — `Call to undefined function cm_ctx()`.

- [ ] **Step 3: Отправка**

Write `public_html/api/lib/cm/send.php`:

```php
<?php
// Отправка от имени чат-менеджера: бюджет сообщений, ответы, ошибки Telegram.

// Telegram пускает боту 20 сообщений в минуту в одну группу; 15 оставляют
// запас. Кончился бюджет — бот молчит, но удаляет и ограничивает дальше.
const CM_BUDGET    = 15;
const CM_TEMP_SECS = 60;

function cm_ctx(PDO $pdo, array $cfg, callable $send, array $chat, array $msg, int $now): array {
    return [
        'pdo' => $pdo, 'cfg' => $cfg, 'send' => $send, 'chat' => $chat, 'msg' => $msg, 'now' => $now,
        'actor' => (int)($msg['from']['id'] ?? 0), 'rank' => 0, 'edited' => false, 'timer' => false,
        'bot' => ltrim((string)($cfg['tg_bot_name'] ?? ''), '@'),
        'thread_id' => !empty($msg['is_topic_message']) ? (int)($msg['message_thread_id'] ?? 0) : 0,
    ];
}

function cm_ok(?array $res): bool {
    return is_array($res) && !empty($res['ok']);
}

// Отказ Telegram → ключ текста. Описания ошибок у Telegram английские и
// стабильные годами; неизвестное — общий отказ.
function cm_tg_error(?array $res): string {
    if (cm_ok($res)) { return ''; }
    $d = strtolower((string)($res['description'] ?? ''));
    if (strpos($d, 'administrator') !== false || strpos($d, 'chat owner') !== false) { return 'target_admin'; }
    if (strpos($d, 'not enough rights') !== false || strpos($d, 'admin_required') !== false
        || strpos($d, 'have no rights') !== false || strpos($d, 'need administrator rights') !== false) { return 'no_rights'; }
    if (strpos($d, 'only for supergroups') !== false) { return 'need_supergroup'; }
    if (strpos($d, 'user not found') !== false || strpos($d, 'participant_id_invalid') !== false) { return 'not_member'; }
    return 'tg_failed';
}

// Одно сообщение из бюджета минуты. В личке бюджета нет.
function cm_budget_take(PDO $pdo, int $chatId, int $now): bool {
    if ($chatId > 0) { return true; }
    $w = intdiv($now, 60);
    $st = $pdo->prepare('UPDATE cm_chats
        SET sent_count = CASE WHEN sent_window = :w THEN sent_count + 1 ELSE 1 END, sent_window = :w
        WHERE chat_id = :c AND (sent_window <> :w OR sent_count < :max)');
    $st->execute([':w' => $w, ':c' => $chatId, ':max' => CM_BUDGET]);
    return $st->rowCount() > 0;
}

/**
 * Сообщение в чат. $o: reply_to — id сообщения, на которое ответить;
 * buttons — inline_keyboard; entities — разметка; temp — удалить через минуту
 * (если в чате не выключено автоудаление). Возвращает message_id или 0.
 */
function cm_say(array $ctx, int $chatId, string $text, array $o = []): int {
    if ($text === '' || !cm_budget_take($ctx['pdo'], $chatId, (int)$ctx['now'])) { return 0; }
    $p = ['chat_id' => $chatId, 'text' => mb_substr($text, 0, 4096), 'link_preview_options' => ['is_disabled' => true]];
    if (!empty($o['reply_to'])) {
        $p['reply_parameters'] = ['message_id' => (int)$o['reply_to'], 'allow_sending_without_reply' => true];
    } elseif (!empty($ctx['thread_id']) && $chatId === (int)$ctx['chat']['chat_id']) {
        $p['message_thread_id'] = (int)$ctx['thread_id'];
    }
    if (!empty($o['entities'])) { $p['entities'] = array_values($o['entities']); }
    if (!empty($o['buttons'])) { $p['reply_markup'] = ['inline_keyboard' => $o['buttons']]; }
    $res = ($ctx['send'])('sendMessage', $p);
    $id = (int)($res['result']['message_id'] ?? 0);
    if ($id > 0 && !empty($o['temp']) && $chatId < 0) {
        $chat = $chatId === (int)$ctx['chat']['chat_id'] ? $ctx['chat'] : cm_chat_row($ctx['pdo'], $chatId);
        if ($chat !== null && !empty($chat['settings']['autodelete'])) {
            cm_queue_add($ctx['pdo'], $chatId, $id, (int)$ctx['now'] + CM_TEMP_SECS);
        }
    }
    return $id;
}

// Ответ в чат контекста на сообщение, вызвавшее команду.
function cm_reply(array $ctx, string $text, bool $temp = true, array $o = []): int {
    $mid = (int)($ctx['msg']['message_id'] ?? 0);
    if ($mid > 0) { $o['reply_to'] = $mid; }
    $o['temp'] = $temp;
    return cm_say($ctx, (int)$ctx['chat']['chat_id'], $text, $o);
}

function cm_reply_err(array $ctx, string $err): void {
    if ($err === 'no_rights') {
        cm_rights_warn($ctx, true);
        return;
    }
    cm_reply($ctx, cm_t($err));
}

// Боту не дали прав. Само по себе (на срабатывании фильтра) — раз в сутки,
// иначе каждое нарушение писало бы одно и то же; на команду — сразу.
function cm_rights_warn(array $ctx, bool $force = false): void {
    $chatId = (int)$ctx['chat']['chat_id'];
    if ($chatId > 0) { return; }
    if (!$force) {
        $st = $ctx['pdo']->prepare('UPDATE cm_chats SET rights_warned_at = :n WHERE chat_id = :c AND rights_warned_at < :d');
        $st->execute([':n' => $ctx['now'], ':c' => $chatId, ':d' => (int)$ctx['now'] - 86400]);
        if ($st->rowCount() === 0) { return; }
    }
    cm_say($ctx, $chatId, cm_t('need_rights'));
}
```

- [ ] **Step 4: Очередь удаления**

Write `public_html/api/lib/cm/queue.php`:

```php
<?php
// Удаление пачками и очередь служебных ответов бота (удаляются через минуту,
// разбирает cron — api/cm_cron.php).

function cm_queue_add(PDO $pdo, int $chatId, int $msgId, int $at): void {
    try {
        $pdo->prepare('INSERT INTO cm_queue (chat_id, message_id, delete_at) VALUES (:c, :m, :a)')
            ->execute([':c' => $chatId, ':m' => $msgId, ':a' => $at]);
    } catch (PDOException $e) {
        // уже в очереди
    }
}

// deleteMessages берёт до 100 id; несуществующие Telegram пропускает сам.
// false — бота лишили прав на удаление.
function cm_delete_many(callable $send, int $chatId, array $ids): bool {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $ok = true;
    foreach (array_chunk($ids, 100) as $chunk) {
        if (cm_tg_error($send('deleteMessages', ['chat_id' => $chatId, 'message_ids' => $chunk])) === 'no_rights') {
            $ok = false;
        }
    }
    return $ok;
}

// Удалить созревшие ответы и вычистить сообщения старше 48 часов.
function cm_queue_tick(PDO $pdo, callable $send, int $now): int {
    $st = $pdo->prepare('SELECT chat_id, message_id FROM cm_queue WHERE delete_at <= :n ORDER BY delete_at, message_id LIMIT 500');
    $st->execute([':n' => $now]);
    $by = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $by[(int)$r['chat_id']][] = (int)$r['message_id']; }
    $del = $pdo->prepare('DELETE FROM cm_queue WHERE chat_id = :c AND message_id = :m');
    $n = 0;
    foreach ($by as $chatId => $ids) {
        cm_delete_many($send, (int)$chatId, $ids);
        foreach ($ids as $m) {
            $del->execute([':c' => $chatId, ':m' => $m]);
            $n++;
        }
    }
    $pdo->prepare('DELETE FROM cm_msgs WHERE at < :t')->execute([':t' => $now - CM_MSG_KEEP]);
    return $n;
}
```

- [ ] **Step 5: Ядро рангов**

Write `public_html/api/lib/cm/ranks.php`:

```php
<?php
// Ранги 0–5 и доступ к командам. Ранг бота и админ Telegram — разные вещи:
// админ Telegram без ранга — 0, но фильтры его не трогают и наказать его бот
// не может (не даёт Telegram). Ранг 5 — owner_id; сначала это создатель
// группы в Telegram, дальше — через «передать создателя».

const CM_SECTIONS = ['ранги', 'варны', 'мут', 'кик', 'бан', 'фильтры', 'триггеры', 'приветствие',
                     'правила', 'репорты', 'чистка', 'заметки', 'таймеры'];

// Перечитать админов Telegram, если кешу больше часа. Не вышло — повтор тоже
// через час: иначе каждое сообщение группы стучалось бы в Telegram.
function cm_admins_refresh(array &$ctx, bool $force = false): void {
    $chat = $ctx['chat'];
    if (!empty($chat['private'])) { return; }
    if (!$force && $chat['admins_at'] > (int)$ctx['now'] - CM_ADMINS_TTL) { return; }
    $res = ($ctx['send'])('getChatAdministrators', ['chat_id' => $chat['chat_id']]);
    $admins = $chat['admins'];
    $creator = $chat['tg_owner_id'];
    if (cm_ok($res) && is_array($res['result'] ?? null)) {
        $admins = [];
        foreach ($res['result'] as $m) {
            $u = is_array($m['user'] ?? null) ? $m['user'] : [];
            $id = (int)($u['id'] ?? 0);
            if ($id <= 0 || !empty($u['is_bot'])) { continue; }
            $admins[] = $id;
            cm_user_touch($ctx['pdo'], $u, (int)$ctx['now']);
            if (($m['status'] ?? '') === 'creator') { $creator = $id; }
        }
    }
    $owner = $chat['owner_id'] > 0 ? $chat['owner_id'] : $creator;
    $ctx['pdo']->prepare('UPDATE cm_chats SET admins = :a, admins_at = :n, tg_owner_id = :t, owner_id = :o WHERE chat_id = :c')
        ->execute([':a' => json_encode($admins), ':n' => $ctx['now'], ':t' => $creator, ':o' => $owner, ':c' => $chat['chat_id']]);
    $ctx['chat']['admins'] = $admins;
    $ctx['chat']['admins_at'] = (int)$ctx['now'];
    $ctx['chat']['tg_owner_id'] = $creator;
    $ctx['chat']['owner_id'] = $owner;
}

function cm_rank(array $ctx, int $userId): int {
    if ($userId <= 0) { return 0; }
    if ($userId === (int)$ctx['chat']['owner_id']) { return 5; }
    if (!empty($ctx['chat']['private'])) { return 0; }
    $m = cm_member($ctx['pdo'], (int)$ctx['chat']['chat_id'], $userId);
    return $m ? max(0, min(4, $m['lvl'])) : 0;
}

function cm_is_tg_admin(array $ctx, int $userId): bool {
    return $userId > 0 && ($userId === (int)$ctx['chat']['tg_owner_id'] || in_array($userId, $ctx['chat']['admins'], true));
}

// Кого не трогают фильтры.
function cm_exempt(array $ctx, int $userId): bool {
    return cm_rank($ctx, $userId) >= 1 || cm_is_tg_admin($ctx, $userId);
}

// Можно ли наказать/понизить цель. '' — можно, иначе ключ текста.
function cm_can_touch(array $ctx, array $t): string {
    if (!empty($t['is_bot'])) { return 'target_bot'; }
    if ((int)$t['id'] === (int)$ctx['actor']) { return 'target_self'; }
    if (cm_is_tg_admin($ctx, (int)$t['id'])) { return 'target_admin'; }
    if (cm_rank($ctx, (int)$t['id']) >= (int)$ctx['rank']) { return 'target_rank'; }
    return '';
}

// Ранг раздела по умолчанию — младший из рангов его команд: «дк» меняет
// уровень всего раздела сразу.
function cm_section_default(string $section): int {
    $min = 6;
    foreach (CM_COMMANDS as $def) {
        if ($def[1] === $section) { $min = min($min, $def[2]); }
    }
    return $min;
}

// Нужный ранг. 6 — команда выключена.
function cm_need_level(array $chat, ?string $section, int $default): int {
    if ($section === null) { return $default; }
    $a = $chat['settings']['access'][$section] ?? null;
    return (is_int($a) && $a >= 0 && $a <= 6) ? $a : $default;
}

function cm_rank_store(PDO $pdo, int $chatId, int $userId, int $old, int $new, int $byId, int $now): void {
    cm_member_set($pdo, $chatId, $userId, ['lvl' => $new]);
    $pdo->prepare('INSERT INTO cm_rank_log (chat_id, user_id, by_id, old_lvl, new_lvl, at) VALUES (:c, :u, :b, :o, :n, :a)')
        ->execute([':c' => $chatId, ':u' => $userId, ':b' => $byId, ':o' => $old, ':n' => $new, ':a' => $now]);
}

// Цель команды или подсказка, как её указать.
function cm_need_target(array $ctx, string &$args): ?array {
    $why = null;
    $t = cm_target($ctx['pdo'], $ctx['msg'], $args, $why);
    if ($t === null) { cm_reply($ctx, cm_t($why === 'unknown' ? 'target_unknown' : 'need_target')); }
    return $t;
}
```

Edit `public_html/api/lib/cm/cm.php`: массив частей → `['db', 'texts', 'parse', 'send', 'queue', 'ranks']`.

- [ ] **Step 6: Run test to verify it passes**

Run: `/c/xampp/php/php.exe tests/cm_core_test.php`
Expected: `0 failures`.

- [ ] **Step 7: Commit**

```bash
git add public_html/api/lib/cm/send.php public_html/api/lib/cm/queue.php public_html/api/lib/cm/ranks.php public_html/api/lib/cm/cm.php tests/cm_core_test.php
git commit -m "feat(cm): отправка с бюджетом, очередь удаления, ранги" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git show --stat HEAD
```

---

### Task 4: Команды рангов и доступа

**Files:**
- Modify: `public_html/api/lib/cm/ranks.php` (дописать в конец)
- Test: `tests/cm_ranks_test.php`

**Interfaces:**
- Consumes: Task 1–3.
- Produces: `cm_rank_change(array $ctx, array $t, int $new): void`; обработчики `cm_cmd_rank_set`, `cm_cmd_rank_up`, `cm_cmd_rank_down`, `cm_cmd_rank_clear`, `cm_cmd_rank_list`, `cm_cmd_rank_log`, `cm_cmd_owner_pass`, `cm_cmd_owner_restore`, `cm_cmd_access` (все `(array $ctx, array $cmd): void`); `cm_owner_callback(array $ctx, array $q, int $from, int $to): string` — текст ответа на кнопку. Данные кнопки передачи: `cm:o:<from>:<to>`.

- [ ] **Step 1: Write the failing test**

Write `tests/cm_ranks_test.php`:

```php
<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';

// Команды рангов и «дк»: api/lib/cm/ranks.php.

test('+модер ставит ранг и пишет журнал', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 20, 'vasya', 'Вася');
    $ctx = cm_ctx_for($pdo, $log, CM_OWNER);
    cm_do($ctx, '+модер 2 @vasya');
    assert_eq(2, cm_member($pdo, CM_CHAT, 20)['lvl']);
    assert_eq(true, strpos(cm_last_text($log), 'старший модератор') !== false);
    $row = $pdo->query('SELECT user_id, by_id, old_lvl, new_lvl FROM cm_rank_log')->fetch(PDO::FETCH_NUM);
    assert_eq([20, CM_OWNER, 0, 2], array_map('intval', $row));
    cm_do($ctx, '+модер @vasya 3');
    assert_eq(3, cm_member($pdo, CM_CHAT, 20)['lvl'], 'ранг и после цели');
});

test('назначать можно только ниже своего ранга, 5 — только передачей', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_lvl($pdo, 10, 3);
    cm_lvl($pdo, 11, 3);
    cm_person($pdo, 20, 'vasya');
    cm_person($pdo, 11, 'petya');
    $ctx = cm_ctx_for($pdo, $log, 10);
    cm_do($ctx, '+модер 3 @vasya');
    assert_eq(null, cm_member($pdo, CM_CHAT, 20), 'равный своему — нельзя');
    assert_eq(cm_t('rank_too_high'), cm_last_text($log));
    cm_do($ctx, '+модер 2 @vasya');
    assert_eq(2, cm_member($pdo, CM_CHAT, 20)['lvl']);
    cm_do($ctx, '-модер @petya');
    assert_eq(3, cm_member($pdo, CM_CHAT, 11)['lvl'], 'равного не снять');
    $o = cm_ctx_for($pdo, $log, CM_OWNER);
    cm_do($o, '+модер 5 @vasya');
    assert_eq(2, cm_member($pdo, CM_CHAT, 20)['lvl']);
    assert_eq(cm_t('rank_need_level'), cm_last_text($log));
});

test('повысить, понизить, снять', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 20, 'vasya');
    $ctx = cm_ctx_for($pdo, $log, CM_OWNER);
    cm_do($ctx, 'повысить @vasya');
    cm_do($ctx, 'повысить @vasya');
    assert_eq(2, cm_member($pdo, CM_CHAT, 20)['lvl']);
    cm_do($ctx, 'понизить @vasya');
    assert_eq(1, cm_member($pdo, CM_CHAT, 20)['lvl']);
    cm_do($ctx, 'снять модера @vasya');
    assert_eq(0, cm_member($pdo, CM_CHAT, 20)['lvl']);
    cm_do($ctx, 'понизить @vasya');
    assert_eq(true, strpos(cm_last_text($log), 'уже участник') !== false);
});

test('модеры и журнал рангов', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 20, 'vasya', 'Вася');
    $ctx = cm_ctx_for($pdo, $log, CM_OWNER);
    cm_do($ctx, '+модер 2 @vasya');
    cm_do($ctx, 'модеры');
    assert_eq(cm_t('ranks_head') . "\n5 · Owner\n2 · Вася", cm_last_text($log));
    cm_do($ctx, 'журнал рангов');
    assert_eq(true, strpos(cm_last_text($log), 'Owner: Вася 0 → 2') !== false);
    cm_do($ctx, 'журнал рангов @vasya');
    assert_eq(true, strpos(cm_last_text($log), 'Вася 0 → 2') !== false);
});

test('передача создателя — по кнопке у нового создателя', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 20, 'vasya', 'Вася');
    $ctx = cm_ctx_for($pdo, $log, CM_OWNER);
    cm_do($ctx, 'передать создателя @vasya');
    $btn = cm_calls($log, 'sendMessage')[0][1]['reply_markup']['inline_keyboard'][0][0]['callback_data'];
    assert_eq('cm:o:1:20', $btn);
    $q = ['id' => 'q1', 'from' => ['id' => 20], 'data' => $btn,
          'message' => ['message_id' => 777, 'chat' => ['id' => CM_CHAT], 'text' => 'x']];
    assert_eq(cm_t('not_yours'), cm_owner_callback(cm_ctx_for($pdo, $log, 99), $q, 1, 20));
    $tgt = cm_ctx_for($pdo, $log, 20);
    cm_owner_callback($tgt, $q, 1, 20);
    assert_eq(20, cm_chat_row($pdo, CM_CHAT)['owner_id']);
    $after = cm_ctx_for($pdo, $log, 20);
    assert_eq([5, 4], [cm_rank($after, 20), cm_rank($after, CM_OWNER)]);
    assert_eq(1, count(cm_calls($log, 'editMessageText')));
    assert_eq(cm_t('offer_stale'), cm_owner_callback($tgt, $q, 1, 20), 'повторное нажатие');
});

test('вернуть создателя может только владелец группы в Telegram', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_chat_ensure($pdo, ['id' => CM_CHAT, 'title' => 'Т'], CM_NOW);
    $pdo->exec('UPDATE cm_chats SET owner_id = 20');
    $v = cm_ctx_for($pdo, $log, 20);
    cm_do($v, 'вернуть создателя');
    assert_eq(cm_t('owner_tg_only'), cm_last_text($log));
    $o = cm_ctx_for($pdo, $log, CM_OWNER);
    cm_do($o, 'вернуть создателя');
    assert_eq(CM_OWNER, cm_chat_row($pdo, CM_CHAT)['owner_id']);
    $after = cm_ctx_for($pdo, $log, CM_OWNER);
    assert_eq(4, cm_rank($after, 20));
});

test('дк: таблица, установка, выключение, сброс', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, CM_OWNER);
    cm_do($ctx, 'дк бан 2');
    assert_eq(2, cm_chat_row($pdo, CM_CHAT)['settings']['access']['бан']);
    cm_do($ctx, 'дк мут 6');
    assert_eq(6, cm_chat_row($pdo, CM_CHAT)['settings']['access']['мут']);
    cm_do($ctx, 'дк дк 0');
    assert_eq(true, strpos(cm_last_text($log), 'Разделы:') !== false, '«дк» не настраивается');
    $c = cm_ctx_for($pdo, $log, CM_OWNER);
    cm_do($c, 'дк');
    assert_eq(true, strpos(cm_last_text($log), "бан: 2") !== false);
    assert_eq(true, strpos(cm_last_text($log), "кик: 2") !== false);
    cm_do($c, 'дк сброс');
    assert_eq([], cm_chat_row($pdo, CM_CHAT)['settings']['access']);
});

run_tests();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/c/xampp/php/php.exe tests/cm_ranks_test.php`
Expected: FAIL — `Call to undefined function cm_cmd_rank_set()`.

- [ ] **Step 3: Write minimal implementation**

Append to `public_html/api/lib/cm/ranks.php`:

```php

// --------------------------------------------------------------------------
//  Команды рангов
// --------------------------------------------------------------------------

function cm_rank_change(array $ctx, array $t, int $new): void {
    if (!empty($t['is_bot'])) {
        cm_reply($ctx, cm_t('target_bot'));
        return;
    }
    if ($new < 0 || $new > 4) {
        cm_reply($ctx, cm_t('rank_need_level'));
        return;
    }
    $old = cm_rank($ctx, (int)$t['id']);
    if ($old >= (int)$ctx['rank'] || $new >= (int)$ctx['rank']) {
        cm_reply($ctx, cm_t('rank_too_high'));
        return;
    }
    if ($new === $old) {
        cm_reply($ctx, cm_t('rank_same', ['name' => $t['name'], 'rank' => CM_RANK_NAMES[$old]]));
        return;
    }
    cm_rank_store($ctx['pdo'], (int)$ctx['chat']['chat_id'], (int)$t['id'], $old, $new, (int)$ctx['actor'], (int)$ctx['now']);
    cm_reply($ctx, cm_t('rank_done', ['name' => $t['name'], 'rank' => CM_RANK_NAMES[$new], 'n' => $new]), false);
}

function cm_cmd_rank_set(array $ctx, array $cmd): void {
    $args = $cmd['args'];
    $t = cm_need_target($ctx, $args);
    if ($t === null) { return; }
    if (!preg_match('~^(\d)(?!\d)~', trim($args), $m)) {
        cm_reply($ctx, cm_t('rank_need_level'));
        return;
    }
    cm_rank_change($ctx, $t, (int)$m[1]);
}

function cm_cmd_rank_up(array $ctx, array $cmd): void {
    $args = $cmd['args'];
    $t = cm_need_target($ctx, $args);
    if ($t !== null) { cm_rank_change($ctx, $t, min(4, cm_rank($ctx, (int)$t['id']) + 1)); }
}

function cm_cmd_rank_down(array $ctx, array $cmd): void {
    $args = $cmd['args'];
    $t = cm_need_target($ctx, $args);
    if ($t !== null) { cm_rank_change($ctx, $t, max(0, cm_rank($ctx, (int)$t['id']) - 1)); }
}

function cm_cmd_rank_clear(array $ctx, array $cmd): void {
    $args = $cmd['args'];
    $t = cm_need_target($ctx, $args);
    if ($t !== null) { cm_rank_change($ctx, $t, 0); }
}

function cm_cmd_rank_list(array $ctx, array $cmd): void {
    $pdo = $ctx['pdo'];
    $owner = (int)$ctx['chat']['owner_id'];
    $lines = [];
    if ($owner > 0) { $lines[] = '5 · ' . cm_user_name($pdo, $owner); }
    $st = $pdo->prepare('SELECT user_id, lvl FROM cm_members WHERE chat_id = :c AND lvl > 0 AND user_id <> :o ORDER BY lvl DESC, user_id');
    $st->execute([':c' => $ctx['chat']['chat_id'], ':o' => $owner]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $lines[] = min(4, (int)$r['lvl']) . ' · ' . cm_user_name($pdo, (int)$r['user_id']);
    }
    cm_reply($ctx, $lines ? cm_t('ranks_head') . "\n" . implode("\n", $lines) : cm_t('ranks_empty'), false);
}

function cm_cmd_rank_log(array $ctx, array $cmd): void {
    $pdo = $ctx['pdo'];
    $args = $cmd['args'];
    $t = cm_target($pdo, $ctx['msg'], $args);
    $sql = 'SELECT user_id, by_id, old_lvl, new_lvl, at FROM cm_rank_log WHERE chat_id = :c'
         . ($t ? ' AND user_id = :u' : '') . ' ORDER BY id DESC LIMIT 15';
    $p = [':c' => $ctx['chat']['chat_id']];
    if ($t) { $p[':u'] = $t['id']; }
    $st = $pdo->prepare($sql);
    $st->execute($p);
    $lines = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $lines[] = cm_t('ranklog_line', ['date' => cm_date((int)$r['at']), 'by' => cm_user_name($pdo, (int)$r['by_id']),
            'name' => cm_user_name($pdo, (int)$r['user_id']), 'old' => (int)$r['old_lvl'], 'new' => (int)$r['new_lvl']]);
    }
    cm_reply($ctx, $lines ? cm_t('ranklog_head') . "\n" . implode("\n", $lines) : cm_t('ranklog_empty'), false);
}

// --------------------------------------------------------------------------
//  Создатель
// --------------------------------------------------------------------------

function cm_cmd_owner_pass(array $ctx, array $cmd): void {
    $args = $cmd['args'];
    $t = cm_need_target($ctx, $args);
    if ($t === null) { return; }
    if (!empty($t['is_bot']) || (int)$t['id'] === (int)$ctx['actor']) {
        cm_reply($ctx, cm_t('target_bad'));
        return;
    }
    $data = 'cm:o:' . (int)$ctx['actor'] . ':' . (int)$t['id'];
    cm_reply($ctx, cm_t('owner_offer', ['name' => $t['name'], 'owner' => cm_user_name($ctx['pdo'], (int)$ctx['actor'])]), false,
        ['buttons' => [[['text' => cm_t('btn_accept'), 'callback_data' => $data]]]]);
}

// Кнопку жмёт тот, кому передают. Строка чата перечитывается: предложение
// могло устареть, пока висело.
function cm_owner_callback(array $ctx, array $q, int $from, int $to): string {
    if ((int)$ctx['actor'] !== $to) { return cm_t('not_yours'); }
    $pdo = $ctx['pdo'];
    $chatId = (int)$ctx['chat']['chat_id'];
    $chat = cm_chat_row($pdo, $chatId);
    if ($chat === null || $chat['owner_id'] !== $from) { return cm_t('offer_stale'); }
    $now = (int)$ctx['now'];
    $pdo->prepare('UPDATE cm_chats SET owner_id = :o WHERE chat_id = :c')->execute([':o' => $to, ':c' => $chatId]);
    cm_rank_store($pdo, $chatId, $from, 5, 4, $from, $now);
    $was = cm_member($pdo, $chatId, $to);
    cm_rank_store($pdo, $chatId, $to, $was ? min(4, $was['lvl']) : 0, 5, $from, $now);
    $text = cm_t('owner_done', ['name' => cm_user_name($pdo, $to)]);
    $qm = is_array($q['message'] ?? null) ? $q['message'] : [];
    ($ctx['send'])('editMessageText', ['chat_id' => (int)($qm['chat']['id'] ?? $chatId),
        'message_id' => (int)($qm['message_id'] ?? 0), 'text' => $text]);
    return $text;
}

function cm_cmd_owner_restore(array $ctx, array $cmd): void {
    cm_admins_refresh($ctx, true);
    $me = (int)$ctx['actor'];
    if ($me !== (int)$ctx['chat']['tg_owner_id']) {
        cm_reply($ctx, cm_t('owner_tg_only'));
        return;
    }
    $prev = (int)$ctx['chat']['owner_id'];
    if ($prev === $me) {
        cm_reply($ctx, cm_t('owner_already'));
        return;
    }
    $pdo = $ctx['pdo'];
    $chatId = (int)$ctx['chat']['chat_id'];
    $pdo->prepare('UPDATE cm_chats SET owner_id = :o WHERE chat_id = :c')->execute([':o' => $me, ':c' => $chatId]);
    if ($prev > 0) { cm_rank_store($pdo, $chatId, $prev, 5, 4, $me, (int)$ctx['now']); }
    $was = cm_member($pdo, $chatId, $me);
    cm_rank_store($pdo, $chatId, $me, $was ? min(4, $was['lvl']) : 0, 5, $me, (int)$ctx['now']);
    cm_reply($ctx, cm_t('owner_restored', ['name' => cm_user_name($pdo, $me)]), false);
}

// --------------------------------------------------------------------------
//  Доступ: «дк»
// --------------------------------------------------------------------------

function cm_cmd_access(array $ctx, array $cmd): void {
    $args = cm_norm(trim($cmd['args']));
    $s = $ctx['chat']['settings'];
    if ($args === '') {
        $lines = [];
        foreach (CM_SECTIONS as $sec) {
            $lines[] = $sec . ': ' . cm_need_level($ctx['chat'], $sec, cm_section_default($sec));
        }
        cm_reply($ctx, cm_t('access_head') . "\n" . implode("\n", $lines), false);
        return;
    }
    if ($args === 'сброс') {
        $s['access'] = [];
        cm_settings_save($ctx['pdo'], $ctx['chat'], $s, (int)$ctx['now']);
        cm_reply($ctx, cm_t('access_reset'), false);
        return;
    }
    if (!preg_match('~^(\S+)\s+([0-6])$~u', $args, $m) || !in_array($m[1], CM_SECTIONS, true)) {
        cm_reply($ctx, cm_t('access_usage', ['sections' => implode(', ', CM_SECTIONS)]));
        return;
    }
    $s['access'][$m[1]] = (int)$m[2];
    cm_settings_save($ctx['pdo'], $ctx['chat'], $s, (int)$ctx['now']);
    cm_reply($ctx, cm_t('access_done', ['section' => $m[1], 'level' => (int)$m[2]]), false);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `/c/xampp/php/php.exe tests/cm_ranks_test.php`
Expected: `0 failures`.

- [ ] **Step 5: Commit**

```bash
git add public_html/api/lib/cm/ranks.php tests/cm_ranks_test.php
git commit -m "feat(cm): команды рангов, передача создателя и доступ" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git show --stat HEAD
```

---

### Task 5: Наказания и варны

**Files:**
- Create: `public_html/api/lib/cm/punish.php`
- Modify: `public_html/api/lib/cm/cm.php` — после `'ranks'` добавить `'punish'`
- Test: `tests/cm_punish_test.php`

**Interfaces:**
- Consumes: Task 1–4.
- Produces:
  - Действие триггера: `['kind' => 'delete'|'warn'|'mute'|'kick'|'ban', 'secs' => int]`
  - `cm_mute_perms(bool $can): array`
  - `cm_punish(array $ctx, int $userId, string $kind, int $secs, string $reason, int $byId): string` — `''` или ключ ошибки; пишет `cm_punishments`, у кика и бана ставит `left_at`
  - `cm_lift(array $ctx, int $userId, string $kind, int $byId): string` (`mute` / `ban`)
  - `cm_warns_since(array $ctx): int`, `cm_warns_active(array $ctx, int $userId): int`
  - `cm_warn(array $ctx, int $userId, string $reason, int $byId): array` → `[ошибка, варнов, лимит, итог наказания или '']`
  - `cm_trigger_actions(array $chat, string $key): array` — по умолчанию `spam` → удалить + бан навсегда, `warns` → бан 7 дней, остальные → удалить
  - `cm_parse_actions(string $s, bool $forWarns): ?array` — 1–3 действия, у «варны» без `варн`
  - `cm_action_text(array $a): string`, `cm_actions_text(array $actions): string`
  - `cm_apply_actions(array $ctx, int $userId, int $msgId, array $actions, string $reason, int $byId): string` — удаление первым; возвращает итог без «удалить» (`''`, если было только удаление)
  - Обработчики: `warn, unwarn, unwarn_all, warns, mute, unmute, mutes, kick, ban, unban, bans, warn_limit, warn_ttl, warn_action`

- [ ] **Step 1: Write the failing test**

Write `tests/cm_punish_test.php`:

```php
<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';

// Наказания и варны: api/lib/cm/punish.php.

function pn_mod(PDO $pdo, array &$log, array $answers = []): array {
    cm_lvl($pdo, 10, 3);
    cm_person($pdo, 20, 'vasya', 'Вася');
    return cm_ctx_for($pdo, $log, 10, '', [], $answers);
}

test('мут: параметры Telegram и запись', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = pn_mod($pdo, $log);
    cm_do($ctx, "мут @vasya 2ч\nфлуд");
    $c = cm_calls($log, 'restrictChatMember')[0][1];
    assert_eq([CM_CHAT, 20, CM_NOW + 7200, false, false],
        [$c['chat_id'], $c['user_id'], $c['until_date'], $c['permissions']['can_send_messages'], $c['use_independent_chat_permissions']]);
    $row = $pdo->query('SELECT kind, reason, by_id, until_at FROM cm_punishments')->fetch(PDO::FETCH_ASSOC);
    assert_eq(['mute', 'флуд', 10, CM_NOW + 7200], [$row['kind'], $row['reason'], (int)$row['by_id'], (int)$row['until_at']]);
    assert_eq("🔇 Вася: мут 2 ч.\nПричина: флуд", cm_last_text($log));
});

test('без срока: мут — час, бан — навсегда; кик — бан и сразу разбан', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = pn_mod($pdo, $log);
    cm_do($ctx, 'мут @vasya');
    assert_eq(CM_NOW + 3600, cm_calls($log, 'restrictChatMember')[0][1]['until_date']);
    cm_do($ctx, 'бан @vasya');
    assert_eq(0, cm_calls($log, 'banChatMember')[0][1]['until_date']);
    assert_eq(CM_NOW, cm_member($pdo, CM_CHAT, 20)['left_at']);
    cm_do($ctx, 'кик @vasya');
    assert_eq(2, count(cm_calls($log, 'banChatMember')));
    assert_eq(true, cm_calls($log, 'unbanChatMember')[0][1]['only_if_banned']);
    cm_do($ctx, 'мут @vasya 10с');
    assert_eq(cm_t('term_bad'), cm_last_text($log));
});

test('нельзя трогать равного, админа Telegram и бота', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = pn_mod($pdo, $log);
    cm_lvl($pdo, 30, 3);
    cm_person($pdo, 30, 'petya');
    cm_person($pdo, CM_ADMIN, 'admin');
    cm_do($ctx, 'бан @petya');
    cm_do($ctx, 'бан @admin');
    assert_eq(0, count(cm_calls($log, 'banChatMember')));
    assert_eq(cm_t('target_admin'), cm_last_text($log));
});

test('варны копятся до лимита, потом наказание из триггера и обнуление', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = pn_mod($pdo, $log);
    cm_do($ctx, 'варн @vasya спам');
    assert_eq("⚠️ Вася: варн 1/3.\nПричина: спам", cm_last_text($log));
    cm_do($ctx, 'варн @vasya');
    cm_do($ctx, 'варн @vasya');
    $ban = cm_calls($log, 'banChatMember')[0][1];
    assert_eq(CM_NOW + 604800, $ban['until_date']);
    assert_eq('⚠️ Вася: варн 3/3 — лимит: бан 7 дн.', cm_last_text($log));
    assert_eq(0, cm_warns_active($ctx, 20));
});

test('истёкший варн не считается', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = pn_mod($pdo, $log);
    $pdo->prepare("INSERT INTO cm_punishments (chat_id, user_id, kind, reason, by_id, created_at) VALUES (:c, 20, 'warn', '', 10, :t)")
        ->execute([':c' => CM_CHAT, ':t' => CM_NOW - 2592001]);
    assert_eq(0, cm_warns_active($ctx, 20));
    cm_do($ctx, 'варн @vasya');
    assert_eq(1, cm_warns_active($ctx, 20));
});

test('-варн снимает последний, снять варны — все, варны показывает список', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = pn_mod($pdo, $log);
    cm_do($ctx, 'варн @vasya раз');
    cm_do($ctx, 'варн @vasya два');
    cm_do($ctx, 'варны');
    assert_eq(cm_t('warns_head', ['limit' => 3]) . "\n• Вася — 2/3", cm_last_text($log));
    cm_do($ctx, 'варны @vasya');
    assert_eq(true, strpos(cm_last_text($log), 'два') !== false);
    cm_do($ctx, '-варн @vasya');
    assert_eq(1, cm_warns_active($ctx, 20));
    cm_do($ctx, 'снять варны @vasya');
    assert_eq(0, cm_warns_active($ctx, 20));
    cm_do($ctx, '-варн @vasya');
    assert_eq(cm_t('unwarn_none', ['name' => 'Вася']), cm_last_text($log));
});

test('размут и разбан снимают в Telegram и в записи; муты и баны — действующие', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = pn_mod($pdo, $log);
    cm_do($ctx, 'мут @vasya 1ч');
    cm_do($ctx, 'муты');
    assert_eq(true, strpos(cm_last_text($log), 'Вася') !== false);
    cm_do($ctx, 'размут @vasya');
    assert_eq(true, cm_calls($log, 'restrictChatMember')[1][1]['permissions']['can_send_messages']);
    cm_do($ctx, 'муты');
    assert_eq(cm_t('mutes_head') . "\n" . cm_t('list_empty'), cm_last_text($log));
    cm_do($ctx, 'бан @vasya');
    cm_do($ctx, 'разбан @vasya');
    assert_eq(1, count(cm_calls($log, 'unbanChatMember')));
    $open = (int)$pdo->query("SELECT COUNT(*) FROM cm_punishments WHERE lifted_at = 0 AND kind IN ('mute', 'ban')")->fetchColumn();
    assert_eq(0, $open);
});

test('лимит, срок и наказание варнов настраиваются', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = pn_mod($pdo, $log);
    cm_do($ctx, 'варны лимит 2');
    cm_do($ctx, 'варны срок 7 дней');
    cm_do($ctx, 'варны наказание мут 1д');
    $s = cm_chat_row($pdo, CM_CHAT)['settings'];
    assert_eq([2, 604800, [['kind' => 'mute', 'secs' => 86400]]], [$s['warns']['limit'], $s['warns']['ttl'], $s['triggers']['warns']]);
    cm_do($ctx, 'варны наказание варн');
    assert_eq(cm_t('trigger_usage'), cm_last_text($log));
    cm_do($ctx, 'варны лимит 50');
    assert_eq(cm_t('warn_limit_usage'), cm_last_text($log));
});

test('разбор действий триггера', function () {
    assert_eq([['kind' => 'delete', 'secs' => 0], ['kind' => 'mute', 'secs' => 3600]], cm_parse_actions('удалить, мут', false));
    assert_eq([['kind' => 'ban', 'secs' => 604800]], cm_parse_actions('Бан 7 дней', false));
    assert_eq(null, cm_parse_actions('удалить, варн, кик, бан', false), 'больше трёх');
    assert_eq(null, cm_parse_actions('расстрел', false));
    assert_eq(null, cm_parse_actions('варн', true));
    assert_eq(null, cm_parse_actions('кик 5м', false));
    assert_eq(null, cm_parse_actions('', false));
    assert_eq('удалить, мут 1 ч', cm_actions_text(cm_parse_actions('удалить, мут', false)));
});

test('нет прав у бота — сообщение о правах, записи нет', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $deny = ['restrictChatMember' => ['ok' => false, 'description' => 'Bad Request: not enough rights to restrict/unrestrict chat member']];
    $ctx = pn_mod($pdo, $log, $deny);
    cm_do($ctx, 'мут @vasya');
    assert_eq(0, (int)$pdo->query('SELECT COUNT(*) FROM cm_punishments')->fetchColumn());
    assert_eq(cm_t('need_rights'), cm_last_text($log));
});

test('действия триггера: удаление первым, итог без удаления', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = pn_mod($pdo, $log);
    $done = cm_apply_actions($ctx, 20, 555, [['kind' => 'mute', 'secs' => 600], ['kind' => 'delete', 'secs' => 0]], 'тест', 0);
    assert_eq(['deleteMessage', 'restrictChatMember'], [$log[count($log) - 2][0], $log[count($log) - 1][0]]);
    assert_eq('мут 10 мин', $done);
    assert_eq('', cm_apply_actions($ctx, 20, 556, [['kind' => 'delete', 'secs' => 0]], 'тест', 0));
});

run_tests();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/c/xampp/php/php.exe tests/cm_punish_test.php`
Expected: FAIL — `Call to undefined function cm_cmd_mute()`.

- [ ] **Step 3: Write minimal implementation**

Write `public_html/api/lib/cm/punish.php`:

```php
<?php
// Наказания: варн, мут, кик, бан — в Telegram и в cm_punishments. Сроки
// мута и бана снимает сам Telegram (until_date), cron для них не нужен.

function cm_mute_perms(bool $can): array {
    return array_fill_keys(['can_send_messages', 'can_send_audios', 'can_send_documents', 'can_send_photos',
        'can_send_videos', 'can_send_video_notes', 'can_send_voice_notes', 'can_send_polls',
        'can_send_other_messages', 'can_add_web_page_previews'], $can);
}

/**
 * Наказать и записать. Кик — бан и сразу разбан: человек может вернуться.
 * Варн — только запись. '' — вышло, иначе ключ ошибки (cm_tg_error).
 */
function cm_punish(array $ctx, int $userId, string $kind, int $secs, string $reason, int $byId): string {
    $chatId = (int)$ctx['chat']['chat_id'];
    $now = (int)$ctx['now'];
    $send = $ctx['send'];
    $until = $secs > 0 ? $now + $secs : 0;
    $res = ['ok' => true];
    if ($kind === 'mute') {
        $res = $send('restrictChatMember', ['chat_id' => $chatId, 'user_id' => $userId,
            'permissions' => cm_mute_perms(false), 'use_independent_chat_permissions' => false, 'until_date' => $until]);
    } elseif ($kind === 'ban' || $kind === 'kick') {
        $res = $send('banChatMember', ['chat_id' => $chatId, 'user_id' => $userId,
            'until_date' => $kind === 'ban' ? $until : 0, 'revoke_messages' => false]);
        if ($kind === 'kick' && cm_ok($res)) {
            $send('unbanChatMember', ['chat_id' => $chatId, 'user_id' => $userId, 'only_if_banned' => true]);
        }
    }
    $err = cm_tg_error($res);
    if ($err !== '') { return $err; }
    $ctx['pdo']->prepare('INSERT INTO cm_punishments (chat_id, user_id, kind, reason, by_id, created_at, until_at, lifted_at, lifted_by)
                          VALUES (:c, :u, :k, :r, :b, :n, :t, 0, 0)')
        ->execute([':c' => $chatId, ':u' => $userId, ':k' => $kind, ':r' => mb_substr($reason, 0, 500), ':b' => $byId,
                   ':n' => $now, ':t' => ($kind === 'mute' || $kind === 'ban') ? $until : 0]);
    if ($kind === 'ban' || $kind === 'kick') { cm_member_set($ctx['pdo'], $chatId, $userId, ['left_at' => $now]); }
    return '';
}

function cm_lift(array $ctx, int $userId, string $kind, int $byId): string {
    $chatId = (int)$ctx['chat']['chat_id'];
    if ($kind === 'mute') {
        $res = ($ctx['send'])('restrictChatMember', ['chat_id' => $chatId, 'user_id' => $userId,
            'permissions' => cm_mute_perms(true), 'use_independent_chat_permissions' => false]);
    } else {
        $res = ($ctx['send'])('unbanChatMember', ['chat_id' => $chatId, 'user_id' => $userId, 'only_if_banned' => true]);
    }
    $err = cm_tg_error($res);
    if ($err !== '') { return $err; }
    $ctx['pdo']->prepare('UPDATE cm_punishments SET lifted_at = :n, lifted_by = :b
                          WHERE chat_id = :c AND user_id = :u AND kind = :k AND lifted_at = 0')
        ->execute([':n' => $ctx['now'], ':b' => $byId, ':c' => $chatId, ':u' => $userId, ':k' => $kind]);
    return '';
}

// --------------------------------------------------------------------------
//  Варны
// --------------------------------------------------------------------------

function cm_warns_since(array $ctx): int {
    $ttl = (int)$ctx['chat']['settings']['warns']['ttl'];
    return $ttl > 0 ? (int)$ctx['now'] - $ttl : 0;
}

function cm_warns_active(array $ctx, int $userId): int {
    $st = $ctx['pdo']->prepare("SELECT COUNT(*) FROM cm_punishments
        WHERE chat_id = :c AND user_id = :u AND kind = 'warn' AND lifted_at = 0 AND created_at > :s");
    $st->execute([':c' => $ctx['chat']['chat_id'], ':u' => $userId, ':s' => cm_warns_since($ctx)]);
    return (int)$st->fetchColumn();
}

// Варн. Набран лимит — наказание из триггера «варны», активные варны снимаются.
function cm_warn(array $ctx, int $userId, string $reason, int $byId): array {
    $err = cm_punish($ctx, $userId, 'warn', 0, $reason, $byId);
    if ($err !== '') { return [$err, 0, 0, '']; }
    $limit = max(1, (int)$ctx['chat']['settings']['warns']['limit']);
    $n = cm_warns_active($ctx, $userId);
    if ($n < $limit) { return ['', $n, $limit, '']; }
    $ctx['pdo']->prepare("UPDATE cm_punishments SET lifted_at = :n, lifted_by = 0
        WHERE chat_id = :c AND user_id = :u AND kind = 'warn' AND lifted_at = 0")
        ->execute([':n' => $ctx['now'], ':c' => $ctx['chat']['chat_id'], ':u' => $userId]);
    $actions = array_values(array_filter(cm_trigger_actions($ctx['chat'], 'warns'), function ($a) { return $a['kind'] !== 'warn'; }));
    return ['', $n, $limit, cm_apply_actions($ctx, $userId, 0, $actions, cm_t('reason_warn_limit'), 0)];
}

// --------------------------------------------------------------------------
//  Действия триггеров
// --------------------------------------------------------------------------

function cm_trigger_actions(array $chat, string $key): array {
    $t = $chat['settings']['triggers'][$key] ?? null;
    if (is_array($t) && $t) { return $t; }
    if ($key === 'spam') { return [['kind' => 'delete', 'secs' => 0], ['kind' => 'ban', 'secs' => 0]]; }
    if ($key === 'warns') { return [['kind' => 'ban', 'secs' => 604800]]; }
    return [['kind' => 'delete', 'secs' => 0]];
}

// «удалить, мут 1ч» → действия; null — не разобралось. Для триггера «варны»
// варн запрещён: он бы зациклился.
function cm_parse_actions(string $s, bool $forWarns): ?array {
    $names = ['удалить' => 'delete', 'варн' => 'warn', 'мут' => 'mute', 'кик' => 'kick', 'бан' => 'ban'];
    $out = [];
    foreach (preg_split('~\s*,\s*~u', trim($s)) as $part) {
        $p = cm_norm(trim((string)$part));
        if ($p === '') { continue; }
        if (!preg_match('~^(удалить|варн|мут|кик|бан)(?:\s+(.+))?$~u', $p, $m)) { return null; }
        $kind = $names[$m[1]];
        $rest = $m[2] ?? '';
        $secs = 0;
        if ($kind === 'mute' || $kind === 'ban') {
            $term = $rest === '' ? null : cm_take_term($rest);
            if ($term === -1 || trim($rest) !== '') { return null; }
            $secs = $term ?? ($kind === 'mute' ? 3600 : 0);
        } elseif ($rest !== '') {
            return null;
        }
        if ($forWarns && $kind === 'warn') { return null; }
        $out[] = ['kind' => $kind, 'secs' => $secs];
    }
    return ($out && count($out) <= 3) ? $out : null;
}

function cm_action_text(array $a): string {
    $names = ['delete' => 'удалить', 'warn' => 'варн', 'mute' => 'мут', 'kick' => 'кик', 'ban' => 'бан'];
    $k = (string)$a['kind'];
    $s = $names[$k] ?? $k;
    if ($k === 'mute' || $k === 'ban') { $s .= ' ' . cm_term_text((int)$a['secs']); }
    return $s;
}

function cm_actions_text(array $actions): string {
    return implode(', ', array_map('cm_action_text', $actions));
}

// Выполнить действия. Удаление первым: нарушение не должно висеть, пока идут
// остальные вызовы. Возвращает, что сделано, без «удалить».
function cm_apply_actions(array $ctx, int $userId, int $msgId, array $actions, string $reason, int $byId): string {
    $chatId = (int)$ctx['chat']['chat_id'];
    foreach ($actions as $a) {
        if ($a['kind'] !== 'delete' || $msgId <= 0) { continue; }
        $res = ($ctx['send'])('deleteMessage', ['chat_id' => $chatId, 'message_id' => $msgId]);
        if (cm_tg_error($res) === 'no_rights') { cm_rights_warn($ctx); }
    }
    $done = [];
    foreach ($actions as $a) {
        if ($a['kind'] === 'delete') { continue; }
        if ($a['kind'] === 'warn') {
            [$err, $n, $limit, $after] = cm_warn($ctx, $userId, $reason, $byId);
            if ($err === '') { $done[] = cm_t('done_warn', ['n' => $n, 'limit' => $limit]) . ($after !== '' ? ' → ' . $after : ''); }
            continue;
        }
        $err = cm_punish($ctx, $userId, (string)$a['kind'], (int)$a['secs'], $reason, $byId);
        if ($err === 'no_rights') { cm_rights_warn($ctx); }
        if ($err === '') { $done[] = cm_action_text($a); }
    }
    return implode(', ', $done);
}

// --------------------------------------------------------------------------
//  Команды
// --------------------------------------------------------------------------

function cm_cmd_warn(array $ctx, array $cmd): void { cm_punish_cmd($ctx, $cmd, 'warn'); }
function cm_cmd_mute(array $ctx, array $cmd): void { cm_punish_cmd($ctx, $cmd, 'mute'); }
function cm_cmd_kick(array $ctx, array $cmd): void { cm_punish_cmd($ctx, $cmd, 'kick'); }
function cm_cmd_ban(array $ctx, array $cmd): void { cm_punish_cmd($ctx, $cmd, 'ban'); }

function cm_punish_cmd(array $ctx, array $cmd, string $kind): void {
    $args = $cmd['args'];
    $t = cm_need_target($ctx, $args);
    if ($t === null) { return; }
    $why = cm_can_touch($ctx, $t);
    if ($why !== '') {
        cm_reply($ctx, cm_t($why));
        return;
    }
    $secs = 0;
    if ($kind === 'mute' || $kind === 'ban') {
        $term = cm_take_term($args);
        if ($term === -1) {
            cm_reply($ctx, cm_t('term_bad'));
            return;
        }
        $secs = $term ?? ($kind === 'mute' ? 3600 : 0);
    }
    $reason = cm_reason($args, $cmd['body']);
    $vars = ['name' => $t['name'], 'term' => cm_term_text($secs), 'reason' => cm_reason_line($reason)];
    if ($kind === 'warn') {
        [$err, $n, $limit, $after] = cm_warn($ctx, (int)$t['id'], $reason, (int)$ctx['actor']);
        if ($err !== '') {
            cm_reply_err($ctx, $err);
            return;
        }
        cm_reply($ctx, cm_t($after === '' ? 'warn_done' : 'warn_limit_hit', $vars + ['n' => $n, 'limit' => $limit, 'after' => $after]), false);
        return;
    }
    $err = cm_punish($ctx, (int)$t['id'], $kind, $secs, $reason, (int)$ctx['actor']);
    if ($err !== '') {
        cm_reply_err($ctx, $err);
        return;
    }
    cm_reply($ctx, cm_t($kind . '_done', $vars), false);
}

function cm_cmd_unwarn(array $ctx, array $cmd): void {
    $args = $cmd['args'];
    $t = cm_need_target($ctx, $args);
    if ($t === null) { return; }
    $pdo = $ctx['pdo'];
    $st = $pdo->prepare("SELECT id FROM cm_punishments WHERE chat_id = :c AND user_id = :u AND kind = 'warn'
        AND lifted_at = 0 AND created_at > :s ORDER BY id DESC LIMIT 1");
    $st->execute([':c' => $ctx['chat']['chat_id'], ':u' => $t['id'], ':s' => cm_warns_since($ctx)]);
    $id = $st->fetchColumn();
    if ($id === false) {
        cm_reply($ctx, cm_t('unwarn_none', ['name' => $t['name']]));
        return;
    }
    $pdo->prepare('UPDATE cm_punishments SET lifted_at = :n, lifted_by = :b WHERE id = :id')
        ->execute([':n' => $ctx['now'], ':b' => $ctx['actor'], ':id' => (int)$id]);
    cm_reply($ctx, cm_t('unwarn_done', ['name' => $t['name'], 'n' => cm_warns_active($ctx, (int)$t['id']),
        'limit' => (int)$ctx['chat']['settings']['warns']['limit']]), false);
}

function cm_cmd_unwarn_all(array $ctx, array $cmd): void {
    $args = $cmd['args'];
    $t = cm_need_target($ctx, $args);
    if ($t === null) { return; }
    $n = cm_warns_active($ctx, (int)$t['id']);
    if ($n === 0) {
        cm_reply($ctx, cm_t('unwarn_none', ['name' => $t['name']]));
        return;
    }
    $ctx['pdo']->prepare("UPDATE cm_punishments SET lifted_at = :n, lifted_by = :b
        WHERE chat_id = :c AND user_id = :u AND kind = 'warn' AND lifted_at = 0")
        ->execute([':n' => $ctx['now'], ':b' => $ctx['actor'], ':c' => $ctx['chat']['chat_id'], ':u' => $t['id']]);
    cm_reply($ctx, cm_t('unwarn_all_done', ['name' => $t['name'], 'n' => $n]), false);
}

function cm_cmd_warns(array $ctx, array $cmd): void {
    $pdo = $ctx['pdo'];
    $args = $cmd['args'];
    $t = cm_target($pdo, $ctx['msg'], $args);
    $limit = (int)$ctx['chat']['settings']['warns']['limit'];
    $p = [':c' => $ctx['chat']['chat_id'], ':s' => cm_warns_since($ctx)];
    $lines = [];
    if ($t !== null) {
        $st = $pdo->prepare("SELECT reason, created_at FROM cm_punishments WHERE chat_id = :c AND user_id = :u
            AND kind = 'warn' AND lifted_at = 0 AND created_at > :s ORDER BY id");
        $st->execute($p + [':u' => $t['id']]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $lines[] = '• ' . cm_date((int)$r['created_at']) . ' — ' . ((string)$r['reason'] !== '' ? $r['reason'] : '—');
        }
        $head = cm_t('warns_user_head', ['name' => $t['name'], 'n' => count($lines), 'limit' => $limit]);
    } else {
        $st = $pdo->prepare("SELECT user_id, COUNT(*) AS n FROM cm_punishments WHERE chat_id = :c AND kind = 'warn'
            AND lifted_at = 0 AND created_at > :s GROUP BY user_id ORDER BY n DESC, user_id LIMIT 20");
        $st->execute($p);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $lines[] = '• ' . cm_user_name($pdo, (int)$r['user_id']) . ' — ' . (int)$r['n'] . '/' . $limit;
        }
        $head = cm_t('warns_head', ['limit' => $limit]);
    }
    cm_reply($ctx, $lines ? $head . "\n" . implode("\n", $lines) : cm_t('warns_none'), false);
}

function cm_cmd_unmute(array $ctx, array $cmd): void { cm_lift_cmd($ctx, $cmd, 'mute'); }
function cm_cmd_unban(array $ctx, array $cmd): void { cm_lift_cmd($ctx, $cmd, 'ban'); }

function cm_lift_cmd(array $ctx, array $cmd, string $kind): void {
    $args = $cmd['args'];
    $t = cm_need_target($ctx, $args);
    if ($t === null) { return; }
    $err = cm_lift($ctx, (int)$t['id'], $kind, (int)$ctx['actor']);
    if ($err !== '') {
        cm_reply_err($ctx, $err);
        return;
    }
    cm_reply($ctx, cm_t($kind === 'mute' ? 'unmute_done' : 'unban_done', ['name' => $t['name']]), false);
}

function cm_cmd_mutes(array $ctx, array $cmd): void { cm_active_list($ctx, 'mute', 'mutes_head'); }
function cm_cmd_bans(array $ctx, array $cmd): void { cm_active_list($ctx, 'ban', 'bans_head'); }

function cm_active_list(array $ctx, string $kind, string $head): void {
    $pdo = $ctx['pdo'];
    $st = $pdo->prepare('SELECT user_id, until_at FROM cm_punishments WHERE chat_id = :c AND kind = :k AND lifted_at = 0
        AND (until_at = 0 OR until_at > :n) ORDER BY id DESC LIMIT 40');
    $st->execute([':c' => $ctx['chat']['chat_id'], ':k' => $kind, ':n' => $ctx['now']]);
    $lines = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $uid = (int)$r['user_id'];
        if (isset($lines[$uid]) || count($lines) >= 20) { continue; }
        $until = (int)$r['until_at'];
        $lines[$uid] = '• ' . cm_user_name($pdo, $uid) . ' — '
            . ($until === 0 ? cm_t('until_forever') : cm_t('until_date', ['date' => cm_date($until)]));
    }
    cm_reply($ctx, cm_t($head) . "\n" . ($lines ? implode("\n", $lines) : cm_t('list_empty')), false);
}

function cm_cmd_warn_limit(array $ctx, array $cmd): void {
    if (!preg_match('~^(\d{1,2})$~', trim($cmd['args']), $m) || (int)$m[1] < 1 || (int)$m[1] > 20) {
        cm_reply($ctx, cm_t('warn_limit_usage'));
        return;
    }
    $s = $ctx['chat']['settings'];
    $s['warns']['limit'] = (int)$m[1];
    cm_settings_save($ctx['pdo'], $ctx['chat'], $s, (int)$ctx['now']);
    cm_reply($ctx, cm_t('warn_limit_done', ['n' => (int)$m[1]]), false);
}

function cm_cmd_warn_ttl(array $ctx, array $cmd): void {
    $args = $cmd['args'];
    $term = cm_take_term($args);
    if ($term === null || $term === -1 || trim($args) !== '') {
        cm_reply($ctx, cm_t('warn_ttl_usage'));
        return;
    }
    $s = $ctx['chat']['settings'];
    $s['warns']['ttl'] = $term;
    cm_settings_save($ctx['pdo'], $ctx['chat'], $s, (int)$ctx['now']);
    cm_reply($ctx, cm_t('warn_ttl_done', ['term' => cm_term_text($term)]), false);
}

function cm_cmd_warn_action(array $ctx, array $cmd): void {
    $acts = cm_parse_actions($cmd['args'], true);
    if ($acts === null) {
        cm_reply($ctx, cm_t('trigger_usage'));
        return;
    }
    $s = $ctx['chat']['settings'];
    $s['triggers']['warns'] = $acts;
    cm_settings_save($ctx['pdo'], $ctx['chat'], $s, (int)$ctx['now']);
    cm_reply($ctx, cm_t('trigger_done', ['name' => 'варны', 'actions' => cm_actions_text($acts)]), false);
}
```

Edit `public_html/api/lib/cm/cm.php`: массив частей → `['db', 'texts', 'parse', 'send', 'queue', 'ranks', 'punish']`.

- [ ] **Step 4: Run test to verify it passes**

Run: `/c/xampp/php/php.exe tests/cm_punish_test.php`
Expected: `0 failures`.

- [ ] **Step 5: Commit**

```bash
git add public_html/api/lib/cm/punish.php public_html/api/lib/cm/cm.php tests/cm_punish_test.php
git commit -m "feat(cm): варн, мут, кик, бан и лимит варнов" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git show --stat HEAD
```

---
### Task 6: Фильтры и триггеры

**Files:**
- Create: `public_html/api/lib/cm/words.php`, `public_html/api/lib/cm/filters.php`
- Modify: `public_html/api/lib/cm/cm.php` — после `'punish'` добавить `'words', 'filters'`
- Test: `tests/cm_filters_test.php`

**Interfaces:**
- Consumes: Task 1–5; из `api/lib/chat_filter.php` — `chat_filter_fold`, `chat_filter_link_steps`, `chat_filter_is_host`, `chat_filter_compile`, `chat_filter_word_key`, `chat_filter_word_hit`, `CHAT_FILTER_WORD_RE`.
- Produces:
  - `CM_MAT_WORDS`, `CM_MAT_EXCEPT`
  - `CM_FILTERS` (имя → ключ), `CM_TRIGGER_NAMES` (`CM_FILTERS` + `варны`), `CM_FILTER_ORDER`, `CM_WORDS_MAX` (200), `CM_DOMAINS_MAX` (50)
  - `cm_text_of(array $msg): string`, `cm_entities_of(array $msg): array`
  - `cm_url_host(string $token): string`, `cm_domain_allowed(string $host, array $domains): bool`, `cm_domain_norm(string $raw): string`, `cm_has_link(array $msg, array $domains): bool`
  - `cm_mat_rules(PDO $pdo, int $chatId): array`, `cm_mat_token_hit(string $tok, array $rules): bool`, `cm_has_mat(string $text, array $rules): bool`
  - `cm_is_flood(PDO $pdo, int $chatId, int $userId, string $text, array $flood, int $now): bool`, `cm_is_caps(string $text): bool`, `cm_is_forward(array $msg): bool`
  - `cm_filter_hit(array $ctx, array $msg): string` — ключ первого сработавшего фильтра или `''` (без `spam`)
  - `cm_filter_act(array $ctx, string $key): void` — действия триггера к автору `$ctx['msg']`; молчит, если было только удаление
  - `cm_save(array &$ctx, array $settings): void` — сохранить настройки и обновить `$ctx['chat']`
  - Обработчики: `filter_set, filter_list, word_add, word_del, word_list, domain_add, domain_del, domain_list, autodelete, trigger_set, trigger_clear, trigger_list`

- [ ] **Step 1: Write the failing test**

Write `tests/cm_filters_test.php`:

```php
<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';

// Фильтры и триггеры: api/lib/cm/filters.php, встроенный мат — words.php.

test('ссылки: любые, кроме maknemy.com и доменов чата', function () {
    $d = ['youtube.com'];
    foreach (['зайди на evil.ru', 'https://scam.xyz/free', 't.me/joinchat/abc', 'evil . com', 'www.evil.net',
              'tg://resolve?domain=x'] as $t) {
        assert_eq(true, cm_has_link(['text' => $t], $d), $t);
    }
    foreach (['https://maknemy.com/stock', 'www.maknemy.com', 'смотри youtube.com/watch?v=1', 'm.youtube.com',
              'обычный текст', 'Buddha.Gas', '@username'] as $t) {
        assert_eq(false, cm_has_link(['text' => $t], $d), $t);
    }
    $hidden = ['text' => 'тык', 'entities' => [['type' => 'text_link', 'offset' => 0, 'length' => 3, 'url' => 'https://evil.ru']]];
    assert_eq(true, cm_has_link($hidden, $d), 'ссылка под текстом');
    assert_eq(true, cm_has_link(['caption' => 'фото evil.ru'], $d), 'подпись к фото');
    assert_eq(true, cm_has_link(['text' => 'mаknemy.com'], $d), 'кириллическая а в своём домене');
});

test('мат: корни, двойники, вразрядку; обычные слова не задеваются', function () {
    $rules = chat_filter_compile(CM_MAT_WORDS);
    foreach (['ты сука', 'БЛЯТЬ', 'нахуй', 'пиздец', 'заебал', 'ёбаный', 'с у к а', 'xуй', 'бляяя'] as $t) {
        assert_eq(true, cm_has_mat($t, $rules), $t);
    }
    foreach (['употреблять', 'корабля', 'рубля', 'оскорблять', 'застрахуй машину', 'хлеба', 'небо', 'команда',
              'сукно', 'влюбляться', 'психует', 'Привет всем'] as $t) {
        assert_eq(false, cm_has_mat($t, $rules), $t);
    }
});

test('свои слова чата добавляются к встроенным', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, CM_OWNER);
    cm_do($ctx, '+слово дурак*');
    assert_eq(true, cm_has_mat('нет, дураков', cm_mat_rules($pdo, CM_CHAT)));
    cm_do($ctx, '+слово ДУРАК*');
    assert_eq(cm_t('word_exists', ['word' => 'дурак*']), cm_last_text($log));
    cm_do($ctx, '+слово д');
    assert_eq(cm_t('word_usage'), cm_last_text($log));
    cm_do($ctx, 'слова');
    assert_eq(cm_t('words_head') . "\nдурак*", cm_last_text($log));
    cm_do($ctx, '-слово дурак*');
    assert_eq(false, cm_has_mat('нет, дураков', cm_mat_rules($pdo, CM_CHAT)));
    cm_do($ctx, '-слово дурак*');
    assert_eq(cm_t('word_missing', ['word' => 'дурак*']), cm_last_text($log));
});

test('флуд: N за S секунд или три одинаковых подряд', function () {
    $pdo = cm_test_pdo();
    for ($i = 0; $i < 4; $i++) { cm_msg_log($pdo, CM_CHAT, 200 + $i, 7, 'msg' . $i, CM_NOW - 2); }
    assert_eq(false, cm_is_flood($pdo, CM_CHAT, 7, 'msg3', [5, 10], CM_NOW));
    cm_msg_log($pdo, CM_CHAT, 204, 7, 'msg4', CM_NOW);
    assert_eq(true, cm_is_flood($pdo, CM_CHAT, 7, 'msg4', [5, 10], CM_NOW));
    assert_eq(false, cm_is_flood($pdo, CM_CHAT, 7, 'msg4', [5, 10], CM_NOW + 20), 'окно ушло');
    $p2 = cm_test_pdo();
    foreach ([300, 301, 302] as $id) { cm_msg_log($p2, CM_CHAT, $id, 8, 'КУПИ', CM_NOW - 100); }
    assert_eq(true, cm_is_flood($p2, CM_CHAT, 8, 'купи', [5, 10], CM_NOW));
});

test('капс от 10 букв и 70% заглавных; пересылки', function () {
    assert_eq(true, cm_is_caps('КУПИТЕ ФРУКТЫ ДЁШЕВО'));
    assert_eq(false, cm_is_caps('ОК'));
    assert_eq(false, cm_is_caps('Привет, Как Дела У Вас'));
    assert_eq(true, cm_is_forward(['forward_origin' => ['type' => 'channel']]));
    assert_eq(true, cm_is_forward(['forward_origin' => ['type' => 'chat']]));
    assert_eq(false, cm_is_forward(['forward_origin' => ['type' => 'user']]));
    assert_eq(false, cm_is_forward(['is_automatic_forward' => true, 'forward_origin' => ['type' => 'channel']]));
    assert_eq(true, cm_is_forward(['forward_from_chat' => ['id' => -100]]));
});

test('фильтры по старшинству, выключенный не срабатывает', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, 7, 'СУКА ЗАЙДИ НА EVIL.RU СРОЧНО');
    assert_eq('links', cm_filter_hit($ctx, $ctx['msg']), 'по умолчанию — только ссылки');
    $s = $ctx['chat']['settings'];
    $s['filters']['links'] = false;
    $s['filters']['caps'] = true;
    $s['filters']['mat'] = true;
    cm_settings_save($pdo, $ctx['chat'], $s, CM_NOW);
    assert_eq('mat', cm_filter_hit($ctx, $ctx['msg']), 'мат раньше капса');
    assert_eq('', cm_filter_hit($ctx, cm_gmsg(7, '', ['sticker' => ['file_id' => 'x']])));
    $s['filters']['stickers'] = true;
    cm_settings_save($pdo, $ctx['chat'], $s, CM_NOW);
    assert_eq('stickers', cm_filter_hit($ctx, cm_gmsg(7, '', ['sticker' => ['file_id' => 'x']])));
});

test('нарушение: по умолчанию удаление молча, с варном — сообщение', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, 7, 'зайди на evil.ru');
    cm_filter_act($ctx, 'links');
    assert_eq([1, 0], [count(cm_calls($log, 'deleteMessage')), count(cm_calls($log, 'sendMessage'))]);
    $o = cm_ctx_for($pdo, $log, CM_OWNER);
    cm_do($o, 'триггер ссылки: удалить, варн');
    $log2 = [];
    $ctx2 = cm_ctx_for($pdo, $log2, 7, 'evil.ru');
    cm_filter_act($ctx2, 'links');
    assert_eq('🚫 U7: ссылки → варн 1/3', cm_last_text($log2));
    assert_eq(1, (int)$pdo->query('SELECT COUNT(*) FROM cm_queue')->fetchColumn(), 'сообщение временное');
});

test('команды фильтров и автоудаления', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, CM_OWNER);
    cm_do($ctx, 'фильтр мат вкл');
    cm_do($ctx, 'фильтр флуд 7 10');
    $s = cm_chat_row($pdo, CM_CHAT)['settings'];
    assert_eq([true, true, [7, 10]], [$s['filters']['mat'], $s['filters']['flood'], $s['flood']]);
    cm_do($ctx, 'фильтр сало вкл');
    assert_eq(true, strpos(cm_last_text($log), 'Фильтры: спамеры, ссылки') !== false);
    cm_do($ctx, 'фильтр мат 5 5');
    assert_eq(true, strpos(cm_last_text($log), 'Так:') === 0, 'числа — только у флуда');
    cm_do($ctx, 'фильтры');
    $t = cm_last_text($log);
    assert_eq(true, strpos($t, '✅ мат → удалить') !== false);
    assert_eq(true, strpos($t, '✅ флуд → удалить (7 за 10 с)') !== false);
    assert_eq(true, strpos($t, '▫️ капс') !== false);
    cm_do($ctx, 'фильтр ссылки выкл');
    assert_eq(false, cm_chat_row($pdo, CM_CHAT)['settings']['filters']['links']);
    cm_do($ctx, 'автоудаление выкл');
    assert_eq(false, cm_chat_row($pdo, CM_CHAT)['settings']['autodelete']);
    cm_do($ctx, 'автоудаление да');
    assert_eq(cm_t('autodelete_usage'), cm_last_text($log));
});

test('домены', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, CM_OWNER);
    cm_do($ctx, '+домен https://YouTube.com/watch?v=1');
    assert_eq(['youtube.com'], cm_chat_row($pdo, CM_CHAT)['settings']['domains']);
    cm_do($ctx, '+домен www.youtube.com');
    assert_eq(cm_t('domain_exists', ['domain' => 'youtube.com']), cm_last_text($log));
    cm_do($ctx, '+домен ерунда');
    assert_eq(cm_t('domain_usage'), cm_last_text($log));
    cm_do($ctx, 'домены');
    assert_eq(cm_t('domains_head') . "\nyoutube.com", cm_last_text($log));
    cm_do($ctx, '-домен youtube.com');
    assert_eq([], cm_chat_row($pdo, CM_CHAT)['settings']['domains']);
    cm_do($ctx, '-домен youtube.com');
    assert_eq(cm_t('domain_missing', ['domain' => 'youtube.com']), cm_last_text($log));
});

test('триггеры: установка, список, сброс', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, CM_OWNER);
    cm_do($ctx, 'триггер мат: удалить, мут 1ч');
    assert_eq([['kind' => 'delete', 'secs' => 0], ['kind' => 'mute', 'secs' => 3600]],
        cm_chat_row($pdo, CM_CHAT)['settings']['triggers']['mat']);
    cm_do($ctx, 'триггер сало: удалить');
    assert_eq(cm_t('trigger_usage'), cm_last_text($log));
    cm_do($ctx, 'триггер варны: варн');
    assert_eq(cm_t('trigger_usage'), cm_last_text($log));
    cm_do($ctx, 'триггеры');
    $t = cm_last_text($log);
    assert_eq(true, strpos($t, 'мат → удалить, мут 1 ч') !== false);
    assert_eq(true, strpos($t, 'ссылки → удалить (по умолчанию)') !== false);
    assert_eq(true, strpos($t, 'спамеры → удалить, бан навсегда (по умолчанию)') !== false);
    cm_do($ctx, '-триггер мат');
    assert_eq(false, isset(cm_chat_row($pdo, CM_CHAT)['settings']['triggers']['mat']));
    assert_eq(cm_t('trigger_reset', ['name' => 'мат', 'actions' => 'удалить']), cm_last_text($log));
});

run_tests();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/c/xampp/php/php.exe tests/cm_filters_test.php`
Expected: FAIL — `Undefined constant "CM_MAT_WORDS"`.

- [ ] **Step 3: Встроенный мат**

Write `public_html/api/lib/cm/words.php`:

```php
<?php
// Встроенный фильтр мата. Записи — как в фильтре сайта: «слово» целиком,
// «слово*» — начало, «*слово» — конец, «*слово*» — любая часть. Сравнение
// идёт после chat_filter_word_key (двойники букв, «й→и», «0→о»), поэтому «хуи»
// и «хуй», «ё» и «е» — одна запись.

const CM_MAT_WORDS = [
    '*хуй*', '*хуе*', '*хуя*',
    '*пизд*', '*пезд*',
    'еб*', '*заеб*', '*выеб*', '*уеб*', '*наеб*', '*отъеб*', '*съеб*', '*поеб*', '*доеб*',
    '*объеб*', '*разъеб*', '*въеб*', '*проеб*', '*подъеб*', '*изъеб*', '*долбоеб*', '*долбаеб*',
    '*бля*',
    '*пидор*', '*пидар*', '*пидр*', '*гандон*', '*гондон*', '*залуп*',
    '*мудак*', '*мудил*', '*мудач*', '*шлюх*', '*дроч*',
    'сука', 'суки', 'суку', 'сукой', 'суке', 'сучка', 'сучки', 'сучку', 'сучкой', 'сучара', 'сучий',
];

// Обычные слова, внутри которых встречаются корни выше: «употреблять»,
// «корабля», «застрахуй», «психует». Слово с такой частью матом не считается.
const CM_MAT_EXCEPT = [
    'страху', 'психу', 'требл', 'корабл', 'рубл', 'сабл', 'колебл', 'скорбл', 'дробл', 'грабл',
    'стебл', 'самбл', 'жабл', 'любл', 'слабл', 'глубл', 'злобл', 'глобл', 'долбл', 'добл', 'кабл', 'гибл',
];
```

- [ ] **Step 4: Фильтры**

Write `public_html/api/lib/cm/filters.php`:

```php
<?php
// Фильтры сообщений и триггеры: что ловить и что делать с нарушителем.
// Сам, без команды модератора, бот трогает только ссылки и спамеров
// (CM_DEFAULTS в db.php). Поиск ссылок и нормализация слов — из фильтра
// сайта (api/lib/chat_filter.php); его поведение здесь не меняется.

const CM_FILTERS = ['спамеры' => 'spam', 'ссылки' => 'links', 'пересылки' => 'forwards', 'мат' => 'mat',
    'флуд' => 'flood', 'капс' => 'caps', 'стикеры' => 'stickers', 'гифки' => 'gifs', 'голосовые' => 'voice'];
const CM_TRIGGER_NAMES = CM_FILTERS + ['варны' => 'warns'];
// Срабатывает первый подошедший, действия разных фильтров не складываются.
// «спамеры» проверяются раньше всех, ещё до команд (router.php).
const CM_FILTER_ORDER = ['links', 'forwards', 'mat', 'flood', 'caps', 'stickers', 'gifs', 'voice'];
const CM_WORDS_MAX   = 200;
const CM_DOMAINS_MAX = 50;

function cm_text_of(array $msg): string {
    return (string)($msg['text'] ?? $msg['caption'] ?? '');
}

function cm_entities_of(array $msg): array {
    return (array)($msg['entities'] ?? $msg['caption_entities'] ?? []);
}

// --------------------------------------------------------------------------
//  Ссылки
// --------------------------------------------------------------------------

// Адрес кандидата: без схемы, «www , evil» → «www.evil», до первого «:/?#\» или пробела.
function cm_url_host(string $token): string {
    $t = (string)preg_replace('~^[a-z][a-z0-9+.\-]*:(?://)?~i', '', trim($token));
    $t = (string)preg_replace('~^www\s*[.,]\s*~i', 'www.', $t);
    return strtolower((string)preg_replace('~[:/?#\\\\\s].*\z~su', '', $t));
}

// maknemy.com разрешён всегда; домен чата — вместе с поддоменами.
function cm_domain_allowed(string $host, array $domains): bool {
    $host = rtrim(strtolower($host), '.');
    if (strncmp($host, 'www.', 4) === 0) { $host = substr($host, 4); }
    if ($host === '') { return false; }
    foreach (array_merge(['maknemy.com'], $domains) as $d) {
        $d = (string)$d;
        if ($d !== '' && ($host === $d || substr($host, -strlen($d) - 1) === '.' . $d)) { return true; }
    }
    return false;
}

function cm_domain_norm(string $raw): string {
    $h = cm_url_host(mb_strtolower(trim($raw)));
    if (strncmp($h, 'www.', 4) === 0) { $h = substr($h, 4); }
    return preg_match('~^[a-z0-9-]+(\.[a-z0-9-]+)+\z~', $h) ? $h : '';
}

// Есть ли ссылка, кроме разрешённых: ссылки под текстом (text_link) и всё,
// что ищет фильтр сайта, включая обходы вроде «evil . ru». Регулярка не
// справилась с текстом — считаем, что ссылка есть: лучше удалить, чем пропустить.
function cm_has_link(array $msg, array $domains): bool {
    foreach (cm_entities_of($msg) as $e) {
        if (is_array($e) && ($e['type'] ?? '') === 'text_link'
            && !cm_domain_allowed(cm_url_host((string)($e['url'] ?? '')), $domains)) {
            return true;
        }
    }
    $text = cm_text_of($msg);
    if ($text === '') { return false; }
    $folded = chat_filter_fold($text);
    if ($folded === null) { return true; }
    foreach (chat_filter_link_steps() as $step) {
        [$re, $isHost] = $step;
        if (!preg_match_all($re, $folded, $mm)) { continue; }
        foreach ($mm[0] as $tok) {
            $host = cm_url_host((string)$tok);
            if ($isHost && !chat_filter_is_host($host)) { continue; }
            if (cm_domain_allowed($host, $domains)) { continue; }
            return true;
        }
    }
    return false;
}

// --------------------------------------------------------------------------
//  Мат
// --------------------------------------------------------------------------

function cm_mat_rules(PDO $pdo, int $chatId): array {
    $st = $pdo->prepare('SELECT word FROM cm_words WHERE chat_id = :c');
    $st->execute([':c' => $chatId]);
    return chat_filter_compile(array_merge(CM_MAT_WORDS, $st->fetchAll(PDO::FETCH_COLUMN)));
}

function cm_mat_except(): array {
    static $keys = null;
    if ($keys === null) { $keys = array_map('chat_filter_word_key', CM_MAT_EXCEPT); }
    return $keys;
}

function cm_mat_token_hit(string $tok, array $rules): bool {
    $key = chat_filter_word_key($tok);
    $flat = (string)preg_replace('/(.)\1+/u', '$1', $key);
    foreach (cm_mat_except() as $x) {
        if (strpos($key, $x) !== false || strpos($flat, $x) !== false) { return false; }
    }
    return chat_filter_word_hit($tok, $rules);
}

function cm_has_mat(string $text, array $rules): bool {
    $f = chat_filter_fold($text);
    if ($f === null || $f === '') { return false; }
    if (preg_match_all('/[\p{L}\p{N}@$]+/u', $f, $m)) {
        foreach ($m[0] as $tok) {
            if (cm_mat_token_hit((string)$tok, $rules)) { return true; }
        }
    }
    // Вразрядку: «с у к а», «с.у.к.а».
    if (preg_match_all('/(?<![\p{L}\p{N}])\p{L}(?:[\s.\-_*,]+\p{L}(?![\p{L}\p{N}])){2,}/u', $f, $m)) {
        foreach ($m[0] as $sp) {
            if (cm_mat_token_hit((string)preg_replace('/[\s.\-_*,]+/u', '', (string)$sp), $rules)) { return true; }
        }
    }
    return false;
}

// --------------------------------------------------------------------------
//  Флуд, капс, пересылки
// --------------------------------------------------------------------------

// Текущее сообщение к этому моменту уже записано в cm_msgs (router.php).
function cm_is_flood(PDO $pdo, int $chatId, int $userId, string $text, array $flood, int $now): bool {
    $n = max(2, (int)($flood[0] ?? 5));
    $secs = max(2, (int)($flood[1] ?? 10));
    $st = $pdo->prepare('SELECT COUNT(*) FROM cm_msgs WHERE chat_id = :c AND user_id = :u AND at > :s');
    $st->execute([':c' => $chatId, ':u' => $userId, ':s' => $now - $secs]);
    if ((int)$st->fetchColumn() >= $n) { return true; }
    $h = cm_text_hash($text);
    if ($h === '') { return false; }
    $st = $pdo->prepare('SELECT text_hash FROM cm_msgs WHERE chat_id = :c AND user_id = :u ORDER BY message_id DESC LIMIT 3');
    $st->execute([':c' => $chatId, ':u' => $userId]);
    $last = $st->fetchAll(PDO::FETCH_COLUMN);
    return count($last) === 3 && count(array_unique($last)) === 1 && (string)$last[0] === $h;
}

function cm_is_caps(string $text): bool {
    $letters = (int)preg_match_all('/\p{L}/u', $text);
    if ($letters < 10) { return false; }
    return (int)preg_match_all('/\p{Lu}/u', $text) / $letters >= 0.7;
}

// Пересланное из канала или чужой группы. Автопересылка из привязанного
// канала — не пересылка.
function cm_is_forward(array $msg): bool {
    if (!empty($msg['is_automatic_forward'])) { return false; }
    $o = $msg['forward_origin'] ?? null;
    if (is_array($o)) { return in_array((string)($o['type'] ?? ''), ['channel', 'chat'], true); }
    return isset($msg['forward_from_chat']);
}

// --------------------------------------------------------------------------
//  Проверка и действия
// --------------------------------------------------------------------------

function cm_filter_hit(array $ctx, array $msg): string {
    $f = $ctx['chat']['settings']['filters'];
    $chatId = (int)$ctx['chat']['chat_id'];
    $text = cm_text_of($msg);
    foreach (CM_FILTER_ORDER as $k) {
        if (empty($f[$k])) { continue; }
        if ($k === 'links' && cm_has_link($msg, (array)$ctx['chat']['settings']['domains'])) { return $k; }
        if ($k === 'forwards' && cm_is_forward($msg)) { return $k; }
        if ($k === 'mat' && $text !== '' && cm_has_mat($text, cm_mat_rules($ctx['pdo'], $chatId))) { return $k; }
        if ($k === 'flood' && empty($ctx['edited'])
            && cm_is_flood($ctx['pdo'], $chatId, (int)($msg['from']['id'] ?? 0), $text, (array)$ctx['chat']['settings']['flood'], (int)$ctx['now'])) {
            return $k;
        }
        if ($k === 'caps' && cm_is_caps($text)) { return $k; }
        if ($k === 'stickers' && isset($msg['sticker'])) { return $k; }
        if ($k === 'gifs' && isset($msg['animation'])) { return $k; }
        if ($k === 'voice' && (isset($msg['voice']) || isset($msg['video_note']))) { return $k; }
    }
    return '';
}

// Действия триггера к автору сообщения контекста. Только удаление — молча:
// в живом чате иначе пойдёт поток извещений.
function cm_filter_act(array $ctx, string $key): void {
    $msg = $ctx['msg'];
    $from = is_array($msg['from'] ?? null) ? $msg['from'] : [];
    $name = (string)array_search($key, CM_FILTERS, true);
    $done = cm_apply_actions($ctx, (int)($from['id'] ?? 0), (int)($msg['message_id'] ?? 0),
        cm_trigger_actions($ctx['chat'], $key), cm_t('reason_filter', ['filter' => $name]), 0);
    if ($done === '') { return; }
    cm_say($ctx, (int)$ctx['chat']['chat_id'],
        cm_t('filter_notice', ['name' => cm_display_name($from), 'filter' => $name, 'done' => $done]), ['temp' => true]);
}

// --------------------------------------------------------------------------
//  Команды
// --------------------------------------------------------------------------

// По ссылке: обработчик дальше читает уже новые настройки.
function cm_save(array &$ctx, array $settings): void {
    cm_settings_save($ctx['pdo'], $ctx['chat'], $settings, (int)$ctx['now']);
}

function cm_cmd_filter_set(array $ctx, array $cmd): void {
    $a = cm_norm(trim($cmd['args']));
    $usage = cm_t('filter_usage', ['names' => implode(', ', array_keys(CM_FILTERS))]);
    if (!preg_match('~^(\S+)\s+(?:(вкл|выкл)|(\d{1,2})\s+(\d{1,3}))$~u', $a, $m) || !isset(CM_FILTERS[$m[1]])) {
        cm_reply($ctx, $usage);
        return;
    }
    $key = CM_FILTERS[$m[1]];
    $s = $ctx['chat']['settings'];
    if (isset($m[3]) && $m[3] !== '') {
        $n = (int)$m[3];
        $sec = (int)$m[4];
        if ($key !== 'flood' || $n < 2 || $n > 30 || $sec < 2 || $sec > 120) {
            cm_reply($ctx, $usage);
            return;
        }
        $s['flood'] = [$n, $sec];
        $s['filters']['flood'] = true;
        cm_save($ctx, $s);
        cm_reply($ctx, cm_t('flood_done', ['n' => $n, 's' => $sec]), false);
        return;
    }
    $on = $m[2] === 'вкл';
    $s['filters'][$key] = $on;
    cm_save($ctx, $s);
    cm_reply($ctx, cm_t('filter_done', ['name' => $m[1], 'state' => cm_t($on ? 'state_on' : 'state_off')]), false);
}

function cm_cmd_filter_list(array $ctx, array $cmd): void {
    $s = $ctx['chat']['settings'];
    $lines = [];
    foreach (CM_FILTERS as $name => $key) {
        if (empty($s['filters'][$key])) {
            $lines[] = '▫️ ' . $name;
            continue;
        }
        $line = '✅ ' . $name . ' → ' . cm_actions_text(cm_trigger_actions($ctx['chat'], $key));
        if ($key === 'flood') { $line .= ' (' . (int)$s['flood'][0] . ' за ' . (int)$s['flood'][1] . ' с)'; }
        $lines[] = $line;
    }
    cm_reply($ctx, cm_t('filters_head') . "\n" . implode("\n", $lines), false);
}

function cm_cmd_word_add(array $ctx, array $cmd): void {
    $w = mb_strtolower(trim($cmd['args']));
    if (!preg_match(CHAT_FILTER_WORD_RE, $w)) {
        cm_reply($ctx, cm_t('word_usage'));
        return;
    }
    $pdo = $ctx['pdo'];
    $chatId = (int)$ctx['chat']['chat_id'];
    $st = $pdo->prepare('SELECT COUNT(*) FROM cm_words WHERE chat_id = :c');
    $st->execute([':c' => $chatId]);
    if ((int)$st->fetchColumn() >= CM_WORDS_MAX) {
        cm_reply($ctx, cm_t('words_full', ['max' => CM_WORDS_MAX]));
        return;
    }
    try {
        $pdo->prepare('INSERT INTO cm_words (chat_id, word) VALUES (:c, :w)')->execute([':c' => $chatId, ':w' => $w]);
    } catch (PDOException $e) {
        cm_reply($ctx, cm_t('word_exists', ['word' => $w]));
        return;
    }
    cm_reply($ctx, cm_t('word_added', ['word' => $w]), false);
}

function cm_cmd_word_del(array $ctx, array $cmd): void {
    $w = mb_strtolower(trim($cmd['args']));
    $st = $ctx['pdo']->prepare('DELETE FROM cm_words WHERE chat_id = :c AND word = :w');
    $st->execute([':c' => $ctx['chat']['chat_id'], ':w' => $w]);
    cm_reply($ctx, cm_t($st->rowCount() > 0 ? 'word_removed' : 'word_missing', ['word' => $w]), $st->rowCount() === 0);
}

function cm_cmd_word_list(array $ctx, array $cmd): void {
    $st = $ctx['pdo']->prepare('SELECT word FROM cm_words WHERE chat_id = :c ORDER BY word');
    $st->execute([':c' => $ctx['chat']['chat_id']]);
    $words = $st->fetchAll(PDO::FETCH_COLUMN);
    cm_reply($ctx, $words ? cm_t('words_head') . "\n" . implode(', ', $words) : cm_t('words_empty'), false);
}

function cm_cmd_domain_add(array $ctx, array $cmd): void {
    $d = cm_domain_norm($cmd['args']);
    if ($d === '') {
        cm_reply($ctx, cm_t('domain_usage'));
        return;
    }
    $s = $ctx['chat']['settings'];
    if (in_array($d, $s['domains'], true)) {
        cm_reply($ctx, cm_t('domain_exists', ['domain' => $d]));
        return;
    }
    if (count($s['domains']) >= CM_DOMAINS_MAX) {
        cm_reply($ctx, cm_t('domains_full', ['max' => CM_DOMAINS_MAX]));
        return;
    }
    $s['domains'][] = $d;
    cm_save($ctx, $s);
    cm_reply($ctx, cm_t('domain_added', ['domain' => $d]), false);
}

function cm_cmd_domain_del(array $ctx, array $cmd): void {
    $d = cm_domain_norm($cmd['args']);
    $s = $ctx['chat']['settings'];
    if ($d === '' || !in_array($d, $s['domains'], true)) {
        cm_reply($ctx, cm_t('domain_missing', ['domain' => $d !== '' ? $d : trim($cmd['args'])]));
        return;
    }
    $s['domains'] = array_values(array_diff($s['domains'], [$d]));
    cm_save($ctx, $s);
    cm_reply($ctx, cm_t('domain_removed', ['domain' => $d]), false);
}

function cm_cmd_domain_list(array $ctx, array $cmd): void {
    $d = $ctx['chat']['settings']['domains'];
    cm_reply($ctx, $d ? cm_t('domains_head') . "\n" . implode("\n", $d) : cm_t('domains_empty'), false);
}

function cm_cmd_autodelete(array $ctx, array $cmd): void {
    $a = cm_norm(trim($cmd['args']));
    if ($a !== 'вкл' && $a !== 'выкл') {
        cm_reply($ctx, cm_t('autodelete_usage'));
        return;
    }
    $s = $ctx['chat']['settings'];
    $s['autodelete'] = $a === 'вкл';
    cm_save($ctx, $s);
    cm_reply($ctx, cm_t('autodelete_done', ['state' => cm_t($a === 'вкл' ? 'state_on' : 'state_off')]), false);
}

function cm_cmd_trigger_set(array $ctx, array $cmd): void {
    $acts = null;
    $name = '';
    if (preg_match('~^(\S+?)\s*:?\s+(.+)$~su', trim($cmd['args']), $m)) {
        $name = cm_norm(rtrim($m[1], ':'));
        $key = CM_TRIGGER_NAMES[$name] ?? '';
        $acts = $key === '' ? null : cm_parse_actions($m[2], $key === 'warns');
    }
    if ($acts === null) {
        cm_reply($ctx, cm_t('trigger_usage'));
        return;
    }
    $s = $ctx['chat']['settings'];
    $s['triggers'][CM_TRIGGER_NAMES[$name]] = $acts;
    cm_save($ctx, $s);
    cm_reply($ctx, cm_t('trigger_done', ['name' => $name, 'actions' => cm_actions_text($acts)]), false);
}

function cm_cmd_trigger_clear(array $ctx, array $cmd): void {
    $name = cm_norm(trim($cmd['args'], " \t:"));
    $key = CM_TRIGGER_NAMES[$name] ?? '';
    if ($key === '') {
        cm_reply($ctx, cm_t('trigger_usage'));
        return;
    }
    $s = $ctx['chat']['settings'];
    unset($s['triggers'][$key]);
    cm_save($ctx, $s);
    cm_reply($ctx, cm_t('trigger_reset', ['name' => $name, 'actions' => cm_actions_text(cm_trigger_actions($ctx['chat'], $key))]), false);
}

function cm_cmd_trigger_list(array $ctx, array $cmd): void {
    $lines = [];
    foreach (CM_TRIGGER_NAMES as $name => $key) {
        $own = !empty($ctx['chat']['settings']['triggers'][$key]);
        $lines[] = $name . ' → ' . cm_actions_text(cm_trigger_actions($ctx['chat'], $key)) . ($own ? '' : cm_t('trigger_default'));
    }
    cm_reply($ctx, cm_t('triggers_head') . "\n" . implode("\n", $lines), false);
}
```

Edit `public_html/api/lib/cm/cm.php`: массив частей → `['db', 'texts', 'parse', 'send', 'queue', 'ranks', 'punish', 'words', 'filters']`.

- [ ] **Step 5: Run test to verify it passes**

Run: `/c/xampp/php/php.exe tests/cm_filters_test.php`
Expected: `0 failures`. Если падает кейс мата — правьте `CM_MAT_WORDS` / `CM_MAT_EXCEPT`, а не тест: тест держит обещание «обычные слова не задеваются».

- [ ] **Step 6: Commit**

```bash
git add public_html/api/lib/cm/words.php public_html/api/lib/cm/filters.php public_html/api/lib/cm/cm.php tests/cm_filters_test.php
git commit -m "feat(cm): фильтры ссылок, мата, флуда, капса и медиа с триггерами" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git show --stat HEAD
```

---

### Task 7: Общая база спамеров

**Files:**
- Create: `public_html/api/lib/cm/spam.php`
- Modify: `public_html/api/lib/cm/cm.php` — после `'filters'` добавить `'spam'`
- Test: `tests/cm_spam_test.php`

**Interfaces:**
- Consumes: Task 1–6; `config_id_list()` из `api/_bootstrap.php`; таблица `tg_links` (`user_id` — Roblox id, `chat_id` — id лички = id в Telegram).
- Produces: `cm_staff_roblox(PDO $pdo, int $tgUserId): string`, `cm_is_staff(PDO $pdo, array $cfg, int $tgUserId): bool`, `cm_spam_row(PDO $pdo, int $uid): ?array`, `cm_spam_has(PDO $pdo, int $uid): bool`, `cm_spam_sweep(array $ctx, int $uid): int`; обработчики `spam_add`, `spam_del`, `spam_check` (работают и в группе, и в личке бота — `$ctx['chat']['private']`).

- [ ] **Step 1: Write the failing test**

Write `tests/cm_spam_test.php`:

```php
<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';

// Общая база спамеров: api/lib/cm/spam.php.

// Telegram 500 — Roblox 900 (admin_ids), Telegram 501 — Roblox 901 (moderator_ids).
function sp_staff(PDO $pdo): void {
    $pdo->exec('INSERT INTO tg_links (user_id, chat_id, linked_at) VALUES (900, 500, 0), (901, 501, 0)');
}

test('сотрудник — admin_ids или moderator_ids с привязанным Telegram', function () {
    $pdo = cm_test_pdo();
    sp_staff($pdo);
    assert_eq([true, true, false], [cm_is_staff($pdo, cm_cfg(), 500), cm_is_staff($pdo, cm_cfg(), 501), cm_is_staff($pdo, cm_cfg(), 502)]);
    assert_eq(false, cm_is_staff(test_db(), cm_cfg(), 500), 'без таблицы tg_links');
    assert_eq('900', cm_staff_roblox($pdo, 500));
});

test('не сотрудник базу не трогает; без префикса — молча', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 20, 'spammer');
    $ctx = cm_ctx_for($pdo, $log, 7);
    cm_do($ctx, '!+спамер @spammer');
    assert_eq(false, cm_spam_has($pdo, 20));
    assert_eq(cm_t('spam_staff_only'), cm_last_text($log));
    $log = [];
    cm_do($ctx, '+спамер @spammer');
    assert_eq(0, count(cm_calls($log, 'sendMessage')));
});

test('+спамер: в базу, удалить и забанить здесь и в других чатах с фильтром', function () {
    $pdo = cm_test_pdo();
    sp_staff($pdo);
    $log = [];
    cm_chat_ensure($pdo, ['id' => -2001, 'title' => 'A'], CM_NOW);
    $b = cm_chat_ensure($pdo, ['id' => -2002, 'title' => 'B'], CM_NOW);
    $s = $b['settings'];
    $s['filters']['spam'] = false;
    cm_settings_save($pdo, $b, $s, CM_NOW);
    cm_member_touch($pdo, -2001, 20, CM_NOW);
    cm_member_touch($pdo, -2002, 20, CM_NOW);
    $reply = ['message_id' => 77, 'from' => ['id' => 20, 'first_name' => 'Спамер']];
    $ctx = cm_ctx_for($pdo, $log, 500, '', ['reply_to_message' => $reply]);
    cm_do($ctx, '+спамер реклама казино');
    $row = cm_spam_row($pdo, 20);
    assert_eq(['реклама казино', '900'], [$row['reason'], $row['added_by']]);
    assert_eq(77, cm_calls($log, 'deleteMessage')[0][1]['message_id']);
    $bans = array_map(function ($c) { return $c[1]['chat_id']; }, cm_calls($log, 'banChatMember'));
    assert_eq([CM_CHAT, -2001], $bans, 'в чате с выключенным фильтром не баним');
    assert_eq(cm_t('spam_added', ['name' => 'Спамер', 'n' => 2]), cm_last_text($log));
});

test('сотрудника и бота в базу не внести', function () {
    $pdo = cm_test_pdo();
    sp_staff($pdo);
    $log = [];
    cm_person($pdo, 501, 'moder');
    $ctx = cm_ctx_for($pdo, $log, 500);
    cm_do($ctx, '+спамер @moder');
    assert_eq(false, cm_spam_has($pdo, 501));
    assert_eq(cm_t('spam_bad_target'), cm_last_text($log));
});

test('в личке бота: внести по id, проверить, убрать', function () {
    $pdo = cm_test_pdo();
    sp_staff($pdo);
    $log = [];
    $msg = ['message_id' => 9, 'chat' => ['id' => 500, 'type' => 'private'], 'from' => ['id' => 500, 'first_name' => 'Мод'], 'text' => ''];
    $ctx = cm_ctx($pdo, cm_cfg(), cm_rec($log), cm_private_chat(500), $msg, CM_NOW);
    cm_do($ctx, '+спамер 123456789 скам');
    assert_eq(true, cm_spam_has($pdo, 123456789));
    assert_eq('скам', cm_spam_row($pdo, 123456789)['reason']);
    assert_eq(0, count(cm_calls($log, 'banChatMember')), 'в личке банить негде');
    cm_do($ctx, 'проверить 123456789');
    assert_eq(true, strpos(cm_last_text($log), 'в базе спамеров с') !== false);
    cm_do($ctx, '-спамер 123456789');
    assert_eq(false, cm_spam_has($pdo, 123456789));
    cm_do($ctx, 'проверить 123456789');
    assert_eq(cm_t('spam_no', ['name' => 'id123456789']), cm_last_text($log));
    cm_do($ctx, '-спамер 123456789');
    assert_eq(cm_t('spam_missing', ['name' => 'id123456789']), cm_last_text($log));
});

run_tests();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/c/xampp/php/php.exe tests/cm_spam_test.php`
Expected: FAIL — `Call to undefined function cm_is_staff()`.

- [ ] **Step 3: Write minimal implementation**

Write `public_html/api/lib/cm/spam.php`:

```php
<?php
// Общая база спамеров. Вносят только сотрудники сайта: admin_ids и
// moderator_ids из config с привязанным Telegram (tg_links: Roblox id ↔ id
// лички, он же id человека в Telegram). Модераторы чужих групп в базу не
// пишут — иначе её быстро превратят в инструмент мести.

function cm_staff_roblox(PDO $pdo, int $tgUserId): string {
    try {
        $st = $pdo->prepare('SELECT user_id FROM tg_links WHERE chat_id = :c');
        $st->execute([':c' => $tgUserId]);
        $id = $st->fetchColumn();
    } catch (PDOException $e) {
        return '';
    }
    return $id === false ? '' : (string)$id;
}

function cm_is_staff(PDO $pdo, array $cfg, int $tgUserId): bool {
    $rid = cm_staff_roblox($pdo, $tgUserId);
    if ($rid === '') { return false; }
    return in_array($rid, config_id_list($cfg, 'admin_ids'), true)
        || in_array($rid, config_id_list($cfg, 'moderator_ids'), true);
}

function cm_spam_row(PDO $pdo, int $uid): ?array {
    try {
        $st = $pdo->prepare('SELECT * FROM cm_spam WHERE user_id = :u');
        $st->execute([':u' => $uid]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return null;
    }
    return $r ?: null;
}

function cm_spam_has(PDO $pdo, int $uid): bool {
    return cm_spam_row($pdo, $uid) !== null;
}

// Забанить в других активных чатах, где бот этого человека видел и где фильтр
// «спамеры» включён. До 200 чатов за раз: Telegram пускает около 30 вызовов в
// секунду, а вызов идёт уже после ответа вебхуку.
function cm_spam_sweep(array $ctx, int $uid): int {
    $pdo = $ctx['pdo'];
    $st = $pdo->prepare('SELECT m.chat_id FROM cm_members m JOIN cm_chats c ON c.chat_id = m.chat_id
        WHERE m.user_id = :u AND m.left_at = 0 AND c.active = 1 AND m.chat_id <> :cur LIMIT 200');
    $st->execute([':u' => $uid, ':cur' => (int)$ctx['chat']['chat_id']]);
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $chatId) {
        $chat = cm_chat_row($pdo, (int)$chatId);
        if ($chat === null || empty($chat['settings']['filters']['spam'])) { continue; }
        $c = $ctx;
        $c['chat'] = $chat;
        if (cm_punish($c, $uid, 'ban', 0, cm_t('reason_spam'), 0) === '') { $n++; }
    }
    return $n;
}

// Общая проверка: команда от сотрудника. Чужому — ответ только на префикс.
function cm_spam_staff(array $ctx, array $cmd): bool {
    if (cm_is_staff($ctx['pdo'], $ctx['cfg'], (int)$ctx['actor'])) { return true; }
    if ($cmd['prefixed']) { cm_reply($ctx, cm_t('spam_staff_only')); }
    return false;
}

function cm_cmd_spam_add(array $ctx, array $cmd): void {
    if (!cm_spam_staff($ctx, $cmd)) { return; }
    $pdo = $ctx['pdo'];
    $args = $cmd['args'];
    $t = cm_need_target($ctx, $args);
    if ($t === null) { return; }
    if (!empty($t['is_bot']) || (int)$t['id'] === (int)$ctx['actor'] || cm_is_staff($pdo, $ctx['cfg'], (int)$t['id'])) {
        cm_reply($ctx, cm_t('spam_bad_target'));
        return;
    }
    $p = [':u' => (int)$t['id'], ':r' => cm_reason($args, $cmd['body']),
          ':b' => cm_staff_roblox($pdo, (int)$ctx['actor']), ':n' => (int)$ctx['now']];
    $up = $pdo->prepare('UPDATE cm_spam SET reason = :r, added_by = :b, added_at = :n WHERE user_id = :u');
    $up->execute($p);
    if ($up->rowCount() === 0 && !cm_spam_has($pdo, (int)$t['id'])) {
        $pdo->prepare('INSERT INTO cm_spam (user_id, reason, added_by, added_at) VALUES (:u, :r, :b, :n)')->execute($p);
    }
    $n = 0;
    if (empty($ctx['chat']['private'])) {
        $done = cm_apply_actions($ctx, (int)$t['id'], (int)$t['msg_id'], cm_trigger_actions($ctx['chat'], 'spam'),
            cm_t('reason_spam'), (int)$ctx['actor']);
        if ($done !== '') { $n++; }
    }
    $n += cm_spam_sweep($ctx, (int)$t['id']);
    cm_reply($ctx, cm_t('spam_added', ['name' => $t['name'], 'n' => $n]), false);
}

function cm_cmd_spam_del(array $ctx, array $cmd): void {
    if (!cm_spam_staff($ctx, $cmd)) { return; }
    $args = $cmd['args'];
    $t = cm_need_target($ctx, $args);
    if ($t === null) { return; }
    $st = $ctx['pdo']->prepare('DELETE FROM cm_spam WHERE user_id = :u');
    $st->execute([':u' => (int)$t['id']]);
    cm_reply($ctx, cm_t($st->rowCount() > 0 ? 'spam_removed' : 'spam_missing', ['name' => $t['name']]), false);
}

function cm_cmd_spam_check(array $ctx, array $cmd): void {
    if (!cm_spam_staff($ctx, $cmd)) { return; }
    $args = $cmd['args'];
    $t = cm_need_target($ctx, $args);
    if ($t === null) { return; }
    $row = cm_spam_row($ctx['pdo'], (int)$t['id']);
    if ($row === null) {
        cm_reply($ctx, cm_t('spam_no', ['name' => $t['name']]), false);
        return;
    }
    cm_reply($ctx, cm_t('spam_yes', ['name' => $t['name'], 'date' => cm_date((int)$row['added_at']),
        'reason' => cm_reason_line((string)$row['reason'])]), false);
}
```

Edit `public_html/api/lib/cm/cm.php`: массив частей → `[..., 'words', 'filters', 'spam']`.

- [ ] **Step 4: Run test to verify it passes**

Run: `/c/xampp/php/php.exe tests/cm_spam_test.php`
Expected: `0 failures`.

- [ ] **Step 5: Commit**

```bash
git add public_html/api/lib/cm/spam.php public_html/api/lib/cm/cm.php tests/cm_spam_test.php
git commit -m "feat(cm): общая база спамеров, вносят только сотрудники сайта" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git show --stat HEAD
```

---

### Task 8: Вход, приветствие, правила, статус бота в группе

**Files:**
- Create: `public_html/api/lib/cm/welcome.php`
- Modify: `public_html/api/lib/cm/cm.php` — после `'spam'` добавить `'welcome'`
- Test: `tests/cm_welcome_test.php`

**Interfaces:**
- Consumes: Task 1–7 (`cm_spam_has`, `cm_apply_actions`, `cm_trigger_actions`).
- Produces:
  - `cm_on_join(array $ctx, array $users): void` — спамер → бан; приветствие один раз на человека; больше трёх входов за минуту — одно общее раз в минуту
  - `cm_on_left(array $ctx, array $user): void`
  - `cm_missing_rights(array $member): array` — названия недостающих прав
  - `cm_on_membership(PDO $pdo, array $cfg, callable $send, array $update, int $now): void` — `my_chat_member` (бот) и `chat_member` (люди) в группах
  - Обработчики: `welcome_set, welcome_del, welcome_show, greet_reset, rules_set, rules_del, rules_show`

- [ ] **Step 1: Write the failing test**

Write `tests/cm_welcome_test.php`:

```php
<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';

// Вход, приветствие, правила, статус бота: api/lib/cm/welcome.php.

function wl_greeting(PDO $pdo, string $text): void {
    $log = [];
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), "+приветствие\n" . $text);
}

test('приветствие: один раз на человека, подстановки', function () {
    $pdo = cm_test_pdo();
    wl_greeting($pdo, 'Привет, {имя}! Это {чат}.');
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, 30);
    cm_on_join($ctx, [['id' => 40, 'first_name' => 'Аня']]);
    assert_eq('Привет, Аня! Это Тестовый чат.', cm_last_text($log));
    cm_on_join($ctx, [['id' => 40, 'first_name' => 'Аня']]);
    assert_eq(1, count(cm_calls($log, 'sendMessage')), 'второй раз не приветствуем');
    assert_eq(CM_NOW, cm_member($pdo, CM_CHAT, 40)['joined_at']);
    cm_on_join($ctx, [['id' => 41, 'is_bot' => true, 'first_name' => 'Bot']]);
    assert_eq(1, count(cm_calls($log, 'sendMessage')), 'ботов не приветствуем');
});

test('без текста приветствия никого не приветствуем и не отмечаем', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, 30);
    cm_on_join($ctx, [['id' => 40, 'first_name' => 'Аня']]);
    assert_eq(0, count(cm_calls($log, 'sendMessage')));
    assert_eq(0, cm_member($pdo, CM_CHAT, 40)['greeted_at']);
});

test('рейд: больше трёх за минуту — одно общее приветствие в минуту', function () {
    $pdo = cm_test_pdo();
    wl_greeting($pdo, 'Привет, {имя}!');
    $log = [];
    cm_on_join(cm_ctx_for($pdo, $log, 30), [['id' => 41, 'first_name' => 'А'], ['id' => 42, 'first_name' => 'Б'], ['id' => 43, 'first_name' => 'В']]);
    assert_eq('Привет, А, Б, В!', cm_last_text($log));
    cm_on_join(cm_ctx_for($pdo, $log, 30), [['id' => 44, 'first_name' => 'Г']]);
    assert_eq('Привет, новые участники!', cm_last_text($log));
    cm_on_join(cm_ctx_for($pdo, $log, 30), [['id' => 45, 'first_name' => 'Д']]);
    assert_eq(2, count(cm_calls($log, 'sendMessage')));
});

test('спамер из базы при входе — бан, без приветствия', function () {
    $pdo = cm_test_pdo();
    wl_greeting($pdo, 'Привет, {имя}!');
    $pdo->exec("INSERT INTO cm_spam (user_id, reason, added_by, added_at) VALUES (50, 'скам', '900', 0)");
    $log = [];
    cm_on_join(cm_ctx_for($pdo, $log, 30), [['id' => 50, 'first_name' => 'Скам']]);
    assert_eq(50, cm_calls($log, 'banChatMember')[0][1]['user_id']);
    assert_eq(0, count(cm_calls($log, 'sendMessage')));
});

test('приветствие: показать, выключить, сбросить историю', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $o = cm_ctx_for($pdo, $log, CM_OWNER);
    cm_do($o, '+приветствие');
    assert_eq(cm_t('welcome_usage'), cm_last_text($log));
    cm_do($o, "+приветствие\nЗдравствуй, {имя}");
    cm_do($o, 'приветствие');
    assert_eq('Здравствуй, {имя}', cm_last_text($log));
    cm_member_set($pdo, CM_CHAT, 40, ['greeted_at' => CM_NOW]);
    cm_do($o, 'сброс приветствий');
    assert_eq(0, cm_member($pdo, CM_CHAT, 40)['greeted_at']);
    cm_do($o, '-приветствие');
    cm_do($o, 'приветствие');
    assert_eq(cm_t('welcome_none'), cm_last_text($log));
});

test('правила: с разметкой, показывает всем, удаляются', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $text = "+правила\nБез спама";
    $o = cm_ctx_for($pdo, $log, CM_OWNER, $text, ['entities' => [['type' => 'bold', 'offset' => 9, 'length' => 3]]]);
    cm_do($o, $text);
    $rules = cm_chat_row($pdo, CM_CHAT)['settings']['rules'];
    assert_eq(['Без спама', [['type' => 'bold', 'offset' => 0, 'length' => 3]]], [$rules['text'], $rules['entities']]);
    $r = cm_ctx_for($pdo, $log, 30);
    cm_do($r, 'правила');
    $sent = cm_calls($log, 'sendMessage');
    $last = $sent[count($sent) - 1][1];
    assert_eq(['Без спама', [['type' => 'bold', 'offset' => 0, 'length' => 3]]], [$last['text'], $last['entities']]);
    cm_do($o, '-правила');
    cm_do($r, 'правила');
    assert_eq(cm_t('rules_none'), cm_last_text($log));
});

function wl_bot_update(string $old, string $new, array $rights = []): array {
    return ['my_chat_member' => [
        'chat' => ['id' => CM_CHAT, 'type' => 'supergroup', 'title' => 'Т'], 'from' => ['id' => CM_OWNER],
        'old_chat_member' => ['status' => $old, 'user' => ['id' => 999, 'is_bot' => true]],
        'new_chat_member' => ['status' => $new, 'user' => ['id' => 999, 'is_bot' => true]] + $rights,
    ]];
}

test('бота добавили — знакомство и недостающие права; дали права — подтверждение; убрали — чат гаснет', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_on_membership($pdo, cm_cfg(), cm_rec($log), wl_bot_update('left', 'member'), CM_NOW);
    $t = cm_last_text($log);
    assert_eq(0, strpos($t, cm_t('intro')));
    assert_eq(true, strpos($t, 'Удаление сообщений') !== false && strpos($t, 'Блокировка пользователей') !== false);
    $rights = ['can_delete_messages' => true, 'can_restrict_members' => true];
    cm_on_membership($pdo, cm_cfg(), cm_rec($log), wl_bot_update('member', 'administrator', $rights), CM_NOW + 1);
    assert_eq(cm_t('rights_ok'), cm_last_text($log));
    cm_on_membership($pdo, cm_cfg(), cm_rec($log), wl_bot_update('administrator', 'left'), CM_NOW + 2);
    assert_eq(0, cm_chat_row($pdo, CM_CHAT)['active']);
    $n = count($log);
    $chan = wl_bot_update('left', 'member');
    $chan['my_chat_member']['chat']['type'] = 'channel';
    cm_on_membership($pdo, cm_cfg(), cm_rec($log), $chan, CM_NOW);
    assert_eq($n, count($log), 'каналы — не наша забота');
    assert_eq(['Удаление сообщений'], cm_missing_rights(['status' => 'administrator', 'can_restrict_members' => true]));
});

test('chat_member: вход — как служебное сообщение, выход — отметка', function () {
    $pdo = cm_test_pdo();
    wl_greeting($pdo, 'Привет, {имя}!');
    $log = [];
    $upd = ['chat_member' => [
        'chat' => ['id' => CM_CHAT, 'type' => 'supergroup', 'title' => 'Тестовый чат'], 'from' => ['id' => 60],
        'old_chat_member' => ['status' => 'left', 'user' => ['id' => 60, 'first_name' => 'Оля']],
        'new_chat_member' => ['status' => 'member', 'user' => ['id' => 60, 'first_name' => 'Оля']],
    ]];
    cm_on_membership($pdo, cm_cfg(), cm_rec($log), $upd, CM_NOW);
    assert_eq('Привет, Оля!', cm_last_text($log));
    $upd['chat_member']['old_chat_member']['status'] = 'member';
    $upd['chat_member']['new_chat_member']['status'] = 'left';
    cm_on_membership($pdo, cm_cfg(), cm_rec($log), $upd, CM_NOW + 5);
    assert_eq(CM_NOW + 5, cm_member($pdo, CM_CHAT, 60)['left_at']);
});

run_tests();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/c/xampp/php/php.exe tests/cm_welcome_test.php`
Expected: FAIL — `Call to undefined function cm_cmd_welcome_set()`.

- [ ] **Step 3: Write minimal implementation**

Write `public_html/api/lib/cm/welcome.php`:

```php
<?php
// Вход участников, приветствие и правила, статус самого бота в группе.
// Приветствие — простой текст: подстановка {имя} сдвигала бы разметку
// Telegram. У правил разметка сохраняется.

const CM_RAID_JOINS = 3;   // больше стольких входов за минуту — рейд

function cm_on_join(array $ctx, array $users): void {
    $pdo = $ctx['pdo'];
    $chatId = (int)$ctx['chat']['chat_id'];
    $now = (int)$ctx['now'];
    $tpl = trim((string)$ctx['chat']['settings']['welcome']);
    $names = [];
    foreach ($users as $u) {
        if (!is_array($u) || !empty($u['is_bot'])) { continue; }
        $id = (int)($u['id'] ?? 0);
        if ($id <= 0) { continue; }
        cm_user_touch($pdo, $u, $now);
        cm_member_touch($pdo, $chatId, $id, $now, true);
        if (!empty($ctx['chat']['settings']['filters']['spam']) && cm_spam_has($pdo, $id)) {
            cm_apply_actions($ctx, $id, 0, cm_trigger_actions($ctx['chat'], 'spam'), cm_t('reason_spam'), 0);
            continue;
        }
        if ($tpl === '') { continue; }
        $st = $pdo->prepare('UPDATE cm_members SET greeted_at = :n WHERE chat_id = :c AND user_id = :u AND greeted_at = 0');
        $st->execute([':n' => $now, ':c' => $chatId, ':u' => $id]);
        if ($st->rowCount() > 0) { $names[] = cm_display_name($u); }
    }
    if (!$names) { return; }
    $who = implode(', ', $names);
    $st = $pdo->prepare('SELECT COUNT(*) FROM cm_members WHERE chat_id = :c AND joined_at > :t');
    $st->execute([':c' => $chatId, ':t' => $now - 60]);
    if ((int)$st->fetchColumn() > CM_RAID_JOINS) {
        $s = $ctx['chat']['settings'];
        if ((int)$s['raid_greet_at'] > $now - 60) { return; }
        $s['raid_greet_at'] = $now;
        cm_settings_save($pdo, $ctx['chat'], $s, $now);
        $who = cm_t('welcome_many');
    }
    cm_say($ctx, $chatId, strtr($tpl, ['{имя}' => $who, '{чат}' => (string)$ctx['chat']['title']]));
}

function cm_on_left(array $ctx, array $user): void {
    $id = (int)($user['id'] ?? 0);
    if ($id > 0) { cm_member_set($ctx['pdo'], (int)$ctx['chat']['chat_id'], $id, ['left_at' => (int)$ctx['now']]); }
}

// Каких прав админа боту не хватает. Не админ — не хватает всех.
function cm_missing_rights(array $member): array {
    $out = [];
    $admin = ($member['status'] ?? '') === 'administrator';
    if (!$admin || empty($member['can_delete_messages'])) { $out[] = 'Удаление сообщений'; }
    if (!$admin || empty($member['can_restrict_members'])) { $out[] = 'Блокировка пользователей'; }
    return $out;
}

/**
 * my_chat_member — бота добавили, повысили или убрали; chat_member — человек
 * вошёл или вышел (на бою этот апдейт доходит не всегда, поэтому главный
 * источник входов — служебное сообщение, router.php). Только группы.
 */
function cm_on_membership(PDO $pdo, array $cfg, callable $send, array $update, int $now): void {
    if (!cm_enabled($cfg) || !cm_ready($pdo)) { return; }
    $bot = is_array($update['my_chat_member'] ?? null);
    $m = $bot ? $update['my_chat_member'] : ($update['chat_member'] ?? null);
    if (!is_array($m) || !is_array($m['chat'] ?? null)) { return; }
    if (!in_array((string)($m['chat']['type'] ?? ''), ['group', 'supergroup'], true)) { return; }
    $chatId = (int)$m['chat']['id'];
    $new = is_array($m['new_chat_member'] ?? null) ? $m['new_chat_member'] : [];
    $old = is_array($m['old_chat_member'] ?? null) ? $m['old_chat_member'] : [];
    $ns = (string)($new['status'] ?? '');
    $os = (string)($old['status'] ?? '');
    $gone = in_array($ns, ['left', 'kicked'], true);
    $wasOut = in_array($os, ['left', 'kicked', ''], true);
    if ($bot && $gone) {
        $pdo->prepare('UPDATE cm_chats SET active = 0, updated_at = :n WHERE chat_id = :c')->execute([':n' => $now, ':c' => $chatId]);
        return;
    }
    $chat = cm_chat_ensure($pdo, $m['chat'], $now);
    $ctx = cm_ctx($pdo, $cfg, $send, $chat, ['chat' => $m['chat'], 'from' => is_array($m['from'] ?? null) ? $m['from'] : []], $now);
    if ($bot) {
        cm_admins_refresh($ctx, true);
        $missing = cm_missing_rights($new);
        if ($wasOut) {
            $text = cm_t('intro');
            if ($missing) { $text .= "\n\n" . cm_t('need_rights_list', ['rights' => implode("\n• ", $missing)]); }
            cm_say($ctx, $chatId, $text);
        } elseif ($ns === 'administrator' && !$missing && $os !== 'administrator') {
            cm_say($ctx, $chatId, cm_t('rights_ok'));
        }
        return;
    }
    $user = is_array($new['user'] ?? null) ? $new['user'] : [];
    if ($gone) {
        cm_on_left($ctx, $user);
        return;
    }
    if ($wasOut && in_array($ns, ['member', 'restricted'], true)) { cm_on_join($ctx, [$user]); }
}

// --------------------------------------------------------------------------
//  Команды
// --------------------------------------------------------------------------

function cm_cmd_welcome_set(array $ctx, array $cmd): void {
    $body = trim($cmd['body']);
    if ($body === '' || mb_strlen($body) > 2000) {
        cm_reply($ctx, cm_t('welcome_usage'));
        return;
    }
    $s = $ctx['chat']['settings'];
    $s['welcome'] = $body;
    cm_settings_save($ctx['pdo'], $ctx['chat'], $s, (int)$ctx['now']);
    cm_reply($ctx, cm_t('welcome_done'), false);
}

function cm_cmd_welcome_del(array $ctx, array $cmd): void {
    $s = $ctx['chat']['settings'];
    $s['welcome'] = '';
    cm_settings_save($ctx['pdo'], $ctx['chat'], $s, (int)$ctx['now']);
    cm_reply($ctx, cm_t('welcome_removed'), false);
}

function cm_cmd_welcome_show(array $ctx, array $cmd): void {
    $w = (string)$ctx['chat']['settings']['welcome'];
    cm_reply($ctx, $w !== '' ? $w : cm_t('welcome_none'), $w === '');
}

function cm_cmd_greet_reset(array $ctx, array $cmd): void {
    $ctx['pdo']->prepare('UPDATE cm_members SET greeted_at = 0 WHERE chat_id = :c')->execute([':c' => $ctx['chat']['chat_id']]);
    cm_reply($ctx, cm_t('greet_reset_done'), false);
}

function cm_cmd_rules_set(array $ctx, array $cmd): void {
    $body = $cmd['body'];
    if (trim($body) === '' || mb_strlen($body) > 4000) {
        cm_reply($ctx, cm_t('rules_usage'));
        return;
    }
    $s = $ctx['chat']['settings'];
    $s['rules'] = ['text' => $body, 'entities' => cm_body_entities($ctx['msg'])];
    cm_settings_save($ctx['pdo'], $ctx['chat'], $s, (int)$ctx['now']);
    cm_reply($ctx, cm_t('rules_done'), false);
}

function cm_cmd_rules_del(array $ctx, array $cmd): void {
    $s = $ctx['chat']['settings'];
    $s['rules'] = ['text' => '', 'entities' => []];
    cm_settings_save($ctx['pdo'], $ctx['chat'], $s, (int)$ctx['now']);
    cm_reply($ctx, cm_t('rules_removed'), false);
}

function cm_cmd_rules_show(array $ctx, array $cmd): void {
    $r = $ctx['chat']['settings']['rules'];
    $text = is_array($r) ? (string)($r['text'] ?? '') : '';
    if (trim($text) === '') {
        cm_reply($ctx, cm_t('rules_none'));
        return;
    }
    $ents = is_array($r['entities'] ?? null) ? $r['entities'] : [];
    cm_reply($ctx, $text, false, $ents ? ['entities' => $ents] : []);
}
```

Edit `public_html/api/lib/cm/cm.php`: массив частей → `[..., 'spam', 'welcome']`.

- [ ] **Step 4: Run test to verify it passes**

Run: `/c/xampp/php/php.exe tests/cm_welcome_test.php`
Expected: `0 failures`.

- [ ] **Step 5: Commit**

```bash
git add public_html/api/lib/cm/welcome.php public_html/api/lib/cm/cm.php tests/cm_welcome_test.php
git commit -m "feat(cm): приветствие, правила и знакомство бота с группой" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git show --stat HEAD
```

---
### Task 9: Репорты

**Files:**
- Create: `public_html/api/lib/cm/reports.php`
- Modify: `public_html/api/lib/cm/cm.php` — после `'welcome'` добавить `'reports'`
- Test: `tests/cm_reports_test.php`

**Interfaces:**
- Consumes: Task 1–8.
- Produces: `CM_REPORT_GAP` (300), `cm_msg_link(array $chat, int $msgId): string`, `cm_report_buttons(int $id): array`, `cm_report_card(array $r, PDO $pdo, array $src): string`, `cm_report_callback(array $ctx, array $q, int $id, string $act): string` (`act`: `d` удалить, `m` мут 1 ч, `b` бан, `x` отклонить; ранг проверяется в группе жалобы); обработчики `report, report_list, report_code, report_bind, report_home`. Данные кнопок: `cm:r:<id>:<act>`.

- [ ] **Step 1: Write the failing test**

Write `tests/cm_reports_test.php`:

```php
<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';

// Репорты: api/lib/cm/reports.php.

function rp_reply(): array {
    return ['reply_to_message' => ['message_id' => 70, 'from' => ['id' => 20, 'first_name' => 'Нарушитель']]];
}

test('репорт: только ответом, карточка с кнопками в той же группе, не чаще раза в 5 минут', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, 30, '', rp_reply());
    cm_do($ctx, 'репорт спам в лс');
    $card = cm_calls($log, 'sendMessage')[0][1];
    assert_eq(CM_CHAT, $card['chat_id']);
    assert_eq(true, strpos($card['text'], "На: Нарушитель\nПричина: спам в лс") !== false);
    assert_eq(true, strpos($card['text'], 'https://t.me/c/1234567890/70') !== false);
    assert_eq('cm:r:1:m', $card['reply_markup']['inline_keyboard'][0][1]['callback_data']);
    assert_eq(cm_t('report_sent'), cm_last_text($log));
    cm_do($ctx, 'репорт ещё');
    assert_eq(cm_t('report_wait'), cm_last_text($log));
    $plain = cm_ctx_for($pdo, $log, 31);
    cm_do($plain, '!репорт');
    assert_eq(cm_t('report_need_reply'), cm_last_text($log));
    $n = count($log);
    cm_do($plain, 'репорт');
    assert_eq($n, count($log), 'без префикса и без ответа — молча');
});

test('на модератора не жалуются', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_lvl($pdo, 20, 1);
    $ctx = cm_ctx_for($pdo, $log, 30, '', rp_reply());
    cm_do($ctx, 'репорт он плохой');
    assert_eq(cm_t('report_staff'), cm_last_text($log));
    assert_eq(0, (int)$pdo->query('SELECT COUNT(*) FROM cm_reports')->fetchColumn());
});

test('кнопки: участник не может, модератор мутит, повторно — закрыто', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_do(cm_ctx_for($pdo, $log, 30, '', rp_reply()), 'репорт спам');
    $card = cm_calls($log, 'sendMessage')[0][1];
    $q = ['id' => 'q', 'from' => ['id' => 40], 'data' => 'cm:r:1:m',
          'message' => ['message_id' => 9000, 'chat' => ['id' => CM_CHAT], 'text' => $card['text']]];
    assert_eq(cm_t('no_rank', ['need' => 2]), cm_report_callback(cm_ctx_for($pdo, $log, 41), $q, 1, 'm'));
    cm_lvl($pdo, 40, 2);
    $mod = cm_ctx_for($pdo, $log, 40);
    assert_eq(cm_t('report_handled'), cm_report_callback($mod, $q, 1, 'm'));
    $r = cm_calls($log, 'restrictChatMember')[0][1];
    assert_eq([20, CM_NOW + 3600], [$r['user_id'], $r['until_date']]);
    $edit = cm_calls($log, 'editMessageText')[0][1];
    assert_eq(9000, $edit['message_id']);
    assert_eq(true, strpos($edit['text'], 'мут 1 ч') !== false);
    assert_eq('done', $pdo->query('SELECT status FROM cm_reports')->fetchColumn());
    assert_eq(cm_t('report_closed'), cm_report_callback($mod, $q, 1, 'b'));
});

test('жалобы в отдельный чат админов по одноразовому коду', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), 'репорты в чат');
    $code = cm_chat_row($pdo, CM_CHAT)['settings']['report_code']['code'];
    assert_eq(1, preg_match('~^\d{6}$~', $code));
    $admChat = cm_chat_ensure($pdo, ['id' => -3000, 'type' => 'supergroup', 'title' => 'Админы'], CM_NOW);
    $msg = ['message_id' => 5, 'chat' => ['id' => -3000, 'type' => 'supergroup'], 'from' => ['id' => 99, 'first_name' => 'Чужой'], 'text' => ''];
    cm_do(cm_ctx($pdo, cm_cfg(), cm_rec($log), $admChat, $msg, CM_NOW), 'репорты сюда ' . $code);
    assert_eq(cm_t('report_bad_code'), cm_last_text($log), 'код работает только у получившего');
    $msg['from'] = ['id' => CM_OWNER, 'first_name' => 'Owner'];
    cm_do(cm_ctx($pdo, cm_cfg(), cm_rec($log), $admChat, $msg, CM_NOW), 'репорты сюда ' . $code);
    assert_eq(cm_t('report_bound', ['chat' => 'Тестовый чат']), cm_last_text($log));
    assert_eq(-3000, cm_chat_row($pdo, CM_CHAT)['report_chat_id']);
    assert_eq(null, cm_chat_row($pdo, CM_CHAT)['settings']['report_code'], 'код одноразовый');
    $log = [];
    cm_do(cm_ctx_for($pdo, $log, 30, '', rp_reply()), 'репорт спам');
    assert_eq(-3000, cm_calls($log, 'sendMessage')[0][1]['chat_id']);
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), 'репорты здесь');
    assert_eq(0, cm_chat_row($pdo, CM_CHAT)['report_chat_id']);
});

test('список открытых жалоб', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_do(cm_ctx_for($pdo, $log, 30, '', rp_reply()), 'репорт раз');
    cm_do(cm_ctx_for($pdo, $log, 31, '', rp_reply()), 'репорт два');
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), 'репорты');
    $t = cm_last_text($log);
    assert_eq(0, strpos($t, cm_t('reports_head')));
    assert_eq(true, strpos($t, '#1') !== false && strpos($t, '#2') !== false && strpos($t, 'два') !== false);
});

run_tests();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/c/xampp/php/php.exe tests/cm_reports_test.php`
Expected: FAIL — `Call to undefined function cm_cmd_report()`.

- [ ] **Step 3: Write minimal implementation**

Write `public_html/api/lib/cm/reports.php`:

```php
<?php
// Репорты: «репорт причина» ответом на сообщение нарушителя. Карточка с
// кнопками уходит в ту же группу или в привязанный чат админов. Нажимать
// кнопки может ранг из раздела «репорты» (по умолчанию 2) — в группе жалобы,
// а не в чате, где висит карточка.

const CM_REPORT_GAP = 300;

function cm_msg_link(array $chat, int $msgId): string {
    if ((string)$chat['username'] !== '') { return 'https://t.me/' . $chat['username'] . '/' . $msgId; }
    $id = (string)$chat['chat_id'];
    return strpos($id, '-100') === 0 ? 'https://t.me/c/' . substr($id, 4) . '/' . $msgId : '';
}

function cm_report_buttons(int $id): array {
    $d = 'cm:r:' . $id . ':';
    return [
        [['text' => cm_t('report_btn_del'), 'callback_data' => $d . 'd'], ['text' => cm_t('report_btn_mute'), 'callback_data' => $d . 'm']],
        [['text' => cm_t('report_btn_ban'), 'callback_data' => $d . 'b'], ['text' => cm_t('report_btn_no'), 'callback_data' => $d . 'x']],
    ];
}

function cm_report_card(array $r, PDO $pdo, array $src): string {
    return cm_t('report_card', ['chat' => $src['title'], 'from' => cm_user_name($pdo, (int)$r['reporter_id']),
        'to' => cm_user_name($pdo, (int)$r['target_id']), 'reason' => (string)$r['reason'] !== '' ? $r['reason'] : '—',
        'link' => cm_msg_link($src, (int)$r['message_id'])]);
}

function cm_cmd_report(array $ctx, array $cmd): void {
    $pdo = $ctx['pdo'];
    $chat = $ctx['chat'];
    $now = (int)$ctx['now'];
    $reply = cm_reply_of($ctx['msg']);
    $from = is_array($reply['from'] ?? null) ? $reply['from'] : null;
    if ($from === null || !empty($from['is_bot'])) {
        if ($cmd['prefixed']) { cm_reply($ctx, cm_t('report_need_reply')); }
        return;
    }
    $target = (int)($from['id'] ?? 0);
    if ($target === (int)$ctx['actor']) { return; }
    if (cm_exempt($ctx, $target)) {
        cm_reply($ctx, cm_t('report_staff'));
        return;
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM cm_reports WHERE chat_id = :c AND reporter_id = :r AND created_at > :t');
    $st->execute([':c' => $chat['chat_id'], ':r' => $ctx['actor'], ':t' => $now - CM_REPORT_GAP]);
    if ((int)$st->fetchColumn() > 0) {
        cm_reply($ctx, cm_t('report_wait'));
        return;
    }
    cm_user_touch($pdo, $from, $now);
    $row = ['reporter_id' => (int)$ctx['actor'], 'target_id' => $target, 'reason' => cm_reason($cmd['args'], $cmd['body']),
            'message_id' => (int)($reply['message_id'] ?? 0)];
    $pdo->prepare("INSERT INTO cm_reports (chat_id, message_id, reporter_id, target_id, reason, status, handled_by, created_at, handled_at)
                   VALUES (:c, :m, :r, :t, :why, 'open', 0, :n, 0)")
        ->execute([':c' => $chat['chat_id'], ':m' => $row['message_id'], ':r' => $row['reporter_id'], ':t' => $target,
                   ':why' => $row['reason'], ':n' => $now]);
    $id = (int)$pdo->lastInsertId();
    $dest = (int)$chat['report_chat_id'] !== 0 ? (int)$chat['report_chat_id'] : (int)$chat['chat_id'];
    cm_say($ctx, $dest, cm_report_card($row, $pdo, $chat), ['buttons' => cm_report_buttons($id)]);
    cm_reply($ctx, cm_t('report_sent'));
}

function cm_report_callback(array $ctx, array $q, int $id, string $act): string {
    if (!in_array($act, ['d', 'm', 'b', 'x'], true)) { return ''; }
    $pdo = $ctx['pdo'];
    $st = $pdo->prepare('SELECT * FROM cm_reports WHERE id = :id');
    $st->execute([':id' => $id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r || $r['status'] !== 'open') { return cm_t('report_closed'); }
    $src = cm_chat_row($pdo, (int)$r['chat_id']);
    if ($src === null) { return cm_t('report_closed'); }
    $sctx = $ctx;
    $sctx['chat'] = $src;
    cm_admins_refresh($sctx);
    $sctx['rank'] = cm_rank($sctx, (int)$ctx['actor']);
    $need = cm_need_level($src, 'репорты', 2);
    if ($sctx['rank'] < $need) { return cm_t('no_rank', ['need' => $need]); }
    $target = ['id' => (int)$r['target_id'], 'is_bot' => false];
    $err = '';
    if ($act === 'd') {
        $err = cm_tg_error(($ctx['send'])('deleteMessage', ['chat_id' => $src['chat_id'], 'message_id' => (int)$r['message_id']]));
    } elseif ($act === 'm' || $act === 'b') {
        $err = cm_can_touch($sctx, $target);
        if ($err === '') {
            $err = cm_punish($sctx, $target['id'], $act === 'm' ? 'mute' : 'ban', $act === 'm' ? 3600 : 0,
                cm_t('reason_report'), (int)$ctx['actor']);
        }
    }
    if ($err !== '') { return cm_t($err); }
    $pdo->prepare('UPDATE cm_reports SET status = :s, handled_by = :b, handled_at = :n WHERE id = :id')
        ->execute([':s' => $act === 'x' ? 'rejected' : 'done', ':b' => $ctx['actor'], ':n' => $ctx['now'], ':id' => $id]);
    $what = ['d' => 'удалено', 'm' => 'мут 1 ч', 'b' => 'бан', 'x' => 'отклонено'][$act];
    $qm = is_array($q['message'] ?? null) ? $q['message'] : [];
    ($ctx['send'])('editMessageText', [
        'chat_id' => (int)($qm['chat']['id'] ?? 0), 'message_id' => (int)($qm['message_id'] ?? 0),
        'text' => mb_substr((string)($qm['text'] ?? '') . "\n\n" . cm_t('report_result',
            ['who' => cm_user_name($pdo, (int)$ctx['actor']), 'what' => $what]), 0, 4096),
        'link_preview_options' => ['is_disabled' => true],
    ]);
    return cm_t('report_handled');
}

function cm_cmd_report_list(array $ctx, array $cmd): void {
    $pdo = $ctx['pdo'];
    $st = $pdo->prepare("SELECT * FROM cm_reports WHERE chat_id = :c AND status = 'open' ORDER BY id DESC LIMIT 10");
    $st->execute([':c' => $ctx['chat']['chat_id']]);
    $lines = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $lines[] = '#' . (int)$r['id'] . ' · ' . cm_user_name($pdo, (int)$r['target_id']) . ' — '
            . ((string)$r['reason'] !== '' ? $r['reason'] : '—') . ' ' . cm_msg_link($ctx['chat'], (int)$r['message_id']);
    }
    cm_reply($ctx, $lines ? cm_t('reports_head') . "\n" . implode("\n", $lines) : cm_t('reports_empty'), false);
}

// Привязка чата админов: код в группе, «репорты сюда <код>» — в чате админов,
// тем же человеком и за 10 минут.
function cm_cmd_report_code(array $ctx, array $cmd): void {
    $code = (string)random_int(100000, 999999);
    $s = $ctx['chat']['settings'];
    $s['report_code'] = ['code' => $code, 'by' => (int)$ctx['actor'], 'until' => (int)$ctx['now'] + 600];
    cm_settings_save($ctx['pdo'], $ctx['chat'], $s, (int)$ctx['now']);
    cm_reply($ctx, cm_t('report_code', ['code' => $code]), false);
}

function cm_cmd_report_bind(array $ctx, array $cmd): void {
    if (!preg_match('~^(\d{6})$~', trim($cmd['args']), $m)) {
        if ($cmd['prefixed']) { cm_reply($ctx, cm_t('report_bad_code')); }
        return;
    }
    $pdo = $ctx['pdo'];
    $st = $pdo->prepare('SELECT * FROM cm_chats WHERE settings LIKE :p');
    $st->execute([':p' => '%"code":"' . $m[1] . '"%']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $src = cm_chat_decode($row);
        $c = $src['settings']['report_code'];
        if (!is_array($c) || (string)($c['code'] ?? '') !== $m[1] || (int)($c['by'] ?? 0) !== (int)$ctx['actor']
            || (int)($c['until'] ?? 0) < (int)$ctx['now'] || $src['chat_id'] === (int)$ctx['chat']['chat_id']) {
            continue;
        }
        $s = $src['settings'];
        $s['report_code'] = null;
        cm_settings_save($pdo, $src, $s, (int)$ctx['now']);
        $pdo->prepare('UPDATE cm_chats SET report_chat_id = :d WHERE chat_id = :c')
            ->execute([':d' => (int)$ctx['chat']['chat_id'], ':c' => $src['chat_id']]);
        cm_reply($ctx, cm_t('report_bound', ['chat' => $src['title']]), false);
        return;
    }
    cm_reply($ctx, cm_t('report_bad_code'));
}

function cm_cmd_report_home(array $ctx, array $cmd): void {
    $ctx['pdo']->prepare('UPDATE cm_chats SET report_chat_id = 0 WHERE chat_id = :c')->execute([':c' => $ctx['chat']['chat_id']]);
    cm_reply($ctx, cm_t('report_home'), false);
}
```

Edit `public_html/api/lib/cm/cm.php`: массив частей → `[..., 'welcome', 'reports']`.

- [ ] **Step 4: Run test to verify it passes**

Run: `/c/xampp/php/php.exe tests/cm_reports_test.php`
Expected: `0 failures`.

- [ ] **Step 5: Commit**

```bash
git add public_html/api/lib/cm/reports.php public_html/api/lib/cm/cm.php tests/cm_reports_test.php
git commit -m "feat(cm): репорты с кнопками и чат админов по коду" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git show --stat HEAD
```

---

### Task 10: Чистка

**Files:**
- Create: `public_html/api/lib/cm/cleanup.php`
- Modify: `public_html/api/lib/cm/cm.php` — после `'reports'` добавить `'cleanup'`
- Test: `tests/cm_cleanup_test.php`

**Interfaces:**
- Consumes: Task 1–9.
- Produces: `CM_PURGE_MAX` (100), `CM_MASS_MAX` (200), `cm_mass_candidates(array $ctx, string $kind, int $param, int $limit): array` (`kind`: `d` удалённые, `i` неактивные дольше `$param` секунд, `n` вошедшие за `$param` секунд; без рангов, админов и создателей), `cm_mass_callback(array $ctx, array $q, string $kind, int $param, int $actor): string`; обработчики `purge, kick_deleted, kick_inactive, kick_new`. Данные кнопки: `cm:k:<kind>:<param>:<actor>`.

- [ ] **Step 1: Write the failing test**

Write `tests/cm_cleanup_test.php`:

```php
<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';

// Чистка: api/lib/cm/cleanup.php.

test('чистка N — последние N и сама команда; без числа и ответа — подсказка', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, CM_OWNER);
    $cur = $ctx['msg']['message_id'];
    cm_do($ctx, 'чистка 3');
    assert_eq(range($cur - 3, $cur), cm_calls($log, 'deleteMessages')[0][1]['message_ids']);
    assert_eq(cm_t('purge_done', ['n' => 3]), cm_last_text($log));
    cm_do($ctx, 'чистка 500');
    assert_eq(cm_t('purge_usage'), cm_last_text($log));
    cm_do($ctx, 'чистка');
    assert_eq(cm_t('purge_usage'), cm_last_text($log));
});

test('чистка @ник — его сообщения за 48 часов', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 20, 'vasya');
    cm_msg_log($pdo, CM_CHAT, 10, 20, 'a', CM_NOW);
    cm_msg_log($pdo, CM_CHAT, 11, 20, 'b', CM_NOW);
    cm_msg_log($pdo, CM_CHAT, 9, 20, 'старое', CM_NOW - CM_MSG_KEEP - 1);
    cm_msg_log($pdo, CM_CHAT, 12, 21, 'чужое', CM_NOW);
    $ctx = cm_ctx_for($pdo, $log, CM_OWNER);
    cm_do($ctx, 'чистка @vasya');
    $ids = cm_calls($log, 'deleteMessages')[0][1]['message_ids'];
    sort($ids);
    assert_eq([10, 11, $ctx['msg']['message_id']], $ids);
});

test('чистка ответом — от того сообщения до команды', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, CM_OWNER);
    $cur = $ctx['msg']['message_id'];
    $ctx['msg']['reply_to_message'] = ['message_id' => $cur - 5, 'from' => ['id' => 20]];
    cm_do($ctx, 'чистка');
    assert_eq(range($cur - 5, $cur), cm_calls($log, 'deleteMessages')[0][1]['message_ids']);
});

test('нет права удалять — сообщение о правах', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, CM_OWNER, '', [], ['deleteMessages' => ['ok' => false, 'description' => 'Bad Request: CHAT_ADMIN_REQUIRED']]);
    cm_do($ctx, 'чистка 3');
    assert_eq(cm_t('need_rights'), cm_last_text($log));
});

test('кик неактивных: подтверждение, чужая кнопка, исключение', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $old = CM_NOW - 40 * 86400;
    cm_member_set($pdo, CM_CHAT, 20, ['last_seen' => $old]);
    cm_member_set($pdo, CM_CHAT, 21, ['last_seen' => CM_NOW - 86400]);
    cm_member_set($pdo, CM_CHAT, 22, ['last_seen' => $old, 'lvl' => 1]);
    cm_member_set($pdo, CM_CHAT, CM_ADMIN, ['last_seen' => $old]);
    cm_member_set($pdo, CM_CHAT, 23, ['last_seen' => $old, 'left_at' => CM_NOW - 100]);
    $ctx = cm_ctx_for($pdo, $log, CM_OWNER);
    cm_do($ctx, 'кик неактивных 30 дней');
    $sent = cm_calls($log, 'sendMessage');
    $data = $sent[0][1]['reply_markup']['inline_keyboard'][0][0]['callback_data'];
    assert_eq('cm:k:i:2592000:1', $data);
    assert_eq(cm_t('mass_confirm', ['n' => 1, 'max' => 200]), $sent[0][1]['text']);
    $q = ['id' => 'q', 'data' => $data, 'message' => ['message_id' => 8000, 'chat' => ['id' => CM_CHAT]]];
    assert_eq(cm_t('not_yours'), cm_mass_callback(cm_ctx_for($pdo, $log, 99), $q, 'i', 2592000, 1));
    assert_eq(cm_t('mass_done', ['n' => 1]), cm_mass_callback($ctx, $q, 'i', 2592000, 1));
    assert_eq([20], array_map(function ($c) { return $c[1]['user_id']; }, cm_calls($log, 'banChatMember')));
    assert_eq(CM_NOW, cm_member($pdo, CM_CHAT, 20)['left_at']);
    cm_do($ctx, 'кик неактивных 30');
    assert_eq(cm_t('mass_none'), cm_last_text($log), 'голое число — дни');
});

test('кик удалённых: только аккаунты без имени', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_member_set($pdo, CM_CHAT, 20, ['last_seen' => CM_NOW]);
    cm_member_set($pdo, CM_CHAT, 21, ['last_seen' => CM_NOW]);
    $answers = ['getChatMember' => function ($p) {
        $name = $p['user_id'] === 20 ? '' : 'Петя';
        return ['ok' => true, 'result' => ['status' => 'member', 'user' => ['id' => $p['user_id'], 'first_name' => $name]]];
    }];
    $ctx = cm_ctx_for($pdo, $log, CM_OWNER, '', [], $answers);
    cm_do($ctx, 'кик удалённых');
    assert_eq(cm_t('mass_confirm_deleted', ['n' => 2, 'max' => 200]), cm_last_text($log));
    $q = ['id' => 'q', 'data' => 'cm:k:d:0:1', 'message' => ['message_id' => 8000, 'chat' => ['id' => CM_CHAT]]];
    assert_eq(cm_t('mass_done', ['n' => 1]), cm_mass_callback($ctx, $q, 'd', 0, 1));
    assert_eq([20], array_map(function ($c) { return $c[1]['user_id']; }, cm_calls($log, 'banChatMember')));
});

test('кик новых: по умолчанию за час', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_member_set($pdo, CM_CHAT, 20, ['joined_at' => CM_NOW - 600]);
    cm_member_set($pdo, CM_CHAT, 21, ['joined_at' => CM_NOW - 7200]);
    $ctx = cm_ctx_for($pdo, $log, CM_OWNER);
    assert_eq([20], cm_mass_candidates($ctx, 'n', 3600, 10));
    cm_do($ctx, 'кик новых');
    assert_eq('cm:k:n:3600:1', cm_calls($log, 'sendMessage')[0][1]['reply_markup']['inline_keyboard'][0][0]['callback_data']);
    cm_do($ctx, 'кик новых 10с');
    assert_eq(cm_t('term_bad'), cm_last_text($log));
});

run_tests();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/c/xampp/php/php.exe tests/cm_cleanup_test.php`
Expected: FAIL — `Call to undefined function cm_cmd_purge()`.

- [ ] **Step 3: Write minimal implementation**

Write `public_html/api/lib/cm/cleanup.php`:

```php
<?php
// Чистка: удаление пачкой и массовые кики. Bot API не даёт список
// участников, поэтому массовые кики — только среди тех, кого бот видел
// (cm_members). Перед кикoм — подтверждение кнопкой; за раз до 200 человек,
// после ответа вебхуку.

const CM_PURGE_MAX = 100;
const CM_MASS_MAX  = 200;

function cm_cmd_purge(array $ctx, array $cmd): void {
    $msg = $ctx['msg'];
    $cur = (int)($msg['message_id'] ?? 0);
    $chatId = (int)$ctx['chat']['chat_id'];
    $args = trim($cmd['args']);
    $reply = cm_reply_of($msg);
    if (preg_match('~^(\d{1,3})$~', $args, $m)) {
        $n = (int)$m[1];
        if ($n < 1 || $n > CM_PURGE_MAX) {
            cm_reply($ctx, cm_t('purge_usage'));
            return;
        }
        $ids = range(max(1, $cur - $n), $cur);
    } elseif ($args !== '') {
        $t = cm_need_target($ctx, $args);
        if ($t === null) { return; }
        $st = $ctx['pdo']->prepare('SELECT message_id FROM cm_msgs WHERE chat_id = :c AND user_id = :u AND at > :t
            ORDER BY message_id DESC LIMIT 100');
        $st->execute([':c' => $chatId, ':u' => $t['id'], ':t' => (int)$ctx['now'] - CM_MSG_KEEP]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        $ids[] = $cur;
    } elseif ($reply !== null) {
        $ids = range(max((int)$reply['message_id'], $cur - CM_PURGE_MAX), $cur);
    } else {
        cm_reply($ctx, cm_t('purge_usage'));
        return;
    }
    if (!cm_delete_many($ctx['send'], $chatId, $ids)) {
        cm_rights_warn($ctx, true);
        return;
    }
    cm_say($ctx, $chatId, cm_t('purge_done', ['n' => count($ids) - 1]), ['temp' => true]);
}

function cm_mass_candidates(array $ctx, string $kind, int $param, int $limit): array {
    $now = (int)$ctx['now'];
    $where = 'chat_id = :c AND left_at = 0 AND lvl = 0';
    $p = [':c' => (int)$ctx['chat']['chat_id']];
    if ($kind === 'i') {
        $where .= ' AND last_seen > 0 AND last_seen < :t';
        $p[':t'] = $now - $param;
    } elseif ($kind === 'n') {
        $where .= ' AND joined_at >= :t';
        $p[':t'] = $now - $param;
    }
    $skip = array_merge($ctx['chat']['admins'], [(int)$ctx['chat']['owner_id'], (int)$ctx['chat']['tg_owner_id']]);
    $st = $ctx['pdo']->prepare("SELECT user_id FROM cm_members WHERE $where ORDER BY user_id LIMIT " . (int)($limit + count($skip)));
    $st->execute($p);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $u) {
        $u = (int)$u;
        if (in_array($u, $skip, true)) { continue; }
        $out[] = $u;
        if (count($out) >= $limit) { break; }
    }
    return $out;
}

function cm_mass_ask(array $ctx, string $kind, int $param): void {
    $n = count(cm_mass_candidates($ctx, $kind, $param, 5000));
    if ($n === 0) {
        cm_reply($ctx, cm_t('mass_none'));
        return;
    }
    $data = 'cm:k:' . $kind . ':' . $param . ':' . (int)$ctx['actor'];
    cm_reply($ctx, cm_t($kind === 'd' ? 'mass_confirm_deleted' : 'mass_confirm', ['n' => $n, 'max' => CM_MASS_MAX]), false,
        ['buttons' => [[['text' => cm_t('btn_confirm'), 'callback_data' => $data]]]]);
}

function cm_cmd_kick_deleted(array $ctx, array $cmd): void {
    cm_mass_ask($ctx, 'd', 0);
}

// «кик неактивных 30» — голое число здесь дни, а не минуты.
function cm_cmd_kick_inactive(array $ctx, array $cmd): void {
    $args = trim($cmd['args']);
    if (preg_match('~^(\d{1,3})$~', $args, $m)) {
        $secs = (int)$m[1] * 86400;
    } else {
        $t = $args === '' ? null : cm_take_term($args);
        if ($t === -1 || $t === 0 || trim($args) !== '') {
            cm_reply($ctx, cm_t('inactive_usage'));
            return;
        }
        $secs = $t ?? 2592000;
    }
    cm_mass_ask($ctx, 'i', $secs);
}

function cm_cmd_kick_new(array $ctx, array $cmd): void {
    $args = trim($cmd['args']);
    $t = $args === '' ? null : cm_take_term($args);
    if ($t === -1) {
        cm_reply($ctx, cm_t('term_bad'));
        return;
    }
    if ($t === 0 || trim($args) !== '') {
        cm_reply($ctx, cm_t('new_usage'));
        return;
    }
    cm_mass_ask($ctx, 'n', $t ?? 3600);
}

// Кнопка «Подтвердить»: жмёт тот, кто просил, и ранг у него ещё есть.
function cm_mass_callback(array $ctx, array $q, string $kind, int $param, int $actor): string {
    if ((int)$ctx['actor'] !== $actor) { return cm_t('not_yours'); }
    $need = cm_need_level($ctx['chat'], 'чистка', 3);
    if ((int)$ctx['rank'] < $need) { return cm_t('no_rank', ['need' => $need]); }
    if (!in_array($kind, ['d', 'i', 'n'], true)) { return ''; }
    $list = cm_mass_candidates($ctx, $kind, $param, CM_MASS_MAX + 1);
    $more = count($list) > CM_MASS_MAX;
    $chatId = (int)$ctx['chat']['chat_id'];
    $done = 0;
    foreach (array_slice($list, 0, CM_MASS_MAX) as $uid) {
        if ($kind === 'd') {
            $res = ($ctx['send'])('getChatMember', ['chat_id' => $chatId, 'user_id' => $uid]);
            if (!cm_ok($res)) { continue; }
            $m = is_array($res['result'] ?? null) ? $res['result'] : [];
            if (in_array((string)($m['status'] ?? ''), ['left', 'kicked'], true)) {
                cm_member_set($ctx['pdo'], $chatId, $uid, ['left_at' => (int)$ctx['now']]);
                continue;
            }
            $fn = (string)($m['user']['first_name'] ?? '');
            if ($fn !== '' && $fn !== 'Deleted Account') { continue; }
        }
        if (cm_punish($ctx, $uid, 'kick', 0, cm_t('reason_cleanup'), $actor) === '') { $done++; }
    }
    $text = cm_t('mass_done', ['n' => $done]);
    $qm = is_array($q['message'] ?? null) ? $q['message'] : [];
    ($ctx['send'])('editMessageText', ['chat_id' => (int)($qm['chat']['id'] ?? $chatId), 'message_id' => (int)($qm['message_id'] ?? 0),
        'text' => $text . ($more ? "\n" . cm_t('mass_more') : '')]);
    return $text;
}
```

Edit `public_html/api/lib/cm/cm.php`: массив частей → `[..., 'reports', 'cleanup']`.

- [ ] **Step 4: Run test to verify it passes**

Run: `/c/xampp/php/php.exe tests/cm_cleanup_test.php`
Expected: `0 failures`.

- [ ] **Step 5: Commit**

```bash
git add public_html/api/lib/cm/cleanup.php public_html/api/lib/cm/cm.php tests/cm_cleanup_test.php
git commit -m "feat(cm): чистка сообщений и массовые кики с подтверждением" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git show --stat HEAD
```

---

### Task 11: Заметки и таймеры

**Files:**
- Create: `public_html/api/lib/cm/notes.php`, `public_html/api/lib/cm/timers.php`
- Modify: `public_html/api/lib/cm/cm.php` — после `'cleanup'` добавить `'notes', 'timers'`
- Test: `tests/cm_notes_test.php`

**Interfaces:**
- Consumes: Task 1–10.
- Produces: `CM_NOTES_MAX` (50), `cm_note_name(string $raw): string`, `cm_note_try(array $ctx): bool`; `CM_TIMERS_MAX` (5), `CM_TIMER_MAX_AHEAD` (31622400), `cm_parse_when(string $args, int $now): int` (время запуска или -1); обработчики `note_add, note_del, note_list, timer_add, timer_list, timer_del`. Срабатывание таймеров — Task 12 (`cm_timers_tick`).

- [ ] **Step 1: Write the failing test**

Write `tests/cm_notes_test.php`:

```php
<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';

// Заметки (notes.php) и команды таймеров (timers.php).

test('заметка с разметкой: сохранить, вызвать #имя, список, удалить', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $text = "+заметка Трейды\nКак торговать: читай";
    $o = cm_ctx_for($pdo, $log, CM_OWNER, $text, ['entities' => [['type' => 'bold', 'offset' => 16, 'length' => 3]]]);
    cm_do($o, $text);
    assert_eq(cm_t('note_saved', ['name' => 'трейды']), cm_last_text($log));
    $r = cm_ctx_for($pdo, $log, 30, '#трейды');
    assert_eq(true, cm_note_try($r));
    $sent = cm_calls($log, 'sendMessage');
    $last = $sent[count($sent) - 1][1];
    assert_eq(['Как торговать: читай', [['type' => 'bold', 'offset' => 0, 'length' => 3]], $r['msg']['message_id']],
        [$last['text'], $last['entities'], $last['reply_parameters']['message_id']]);
    assert_eq(true, cm_note_try(cm_ctx_for($pdo, $log, 30, '#ТРЕЙДЫ пожалуйста')));
    assert_eq(false, cm_note_try(cm_ctx_for($pdo, $log, 30, '#нет')));
    assert_eq(false, cm_note_try(cm_ctx_for($pdo, $log, 30, 'текст #трейды')));
    cm_do($o, 'заметки');
    assert_eq(cm_t('notes_head') . "\n#трейды", cm_last_text($log));
    cm_do($o, '-заметка трейды');
    assert_eq(false, cm_note_try(cm_ctx_for($pdo, $log, 30, '#трейды')));
    cm_do($o, '-заметка трейды');
    assert_eq(cm_t('note_missing', ['name' => 'трейды']), cm_last_text($log));
});

test('заметки: имя, пустой текст и лимит', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $o = cm_ctx_for($pdo, $log, CM_OWNER);
    cm_do($o, "+заметка плохое имя!\nтекст");
    assert_eq(cm_t('note_usage'), cm_last_text($log));
    cm_do($o, '+заметка пусто');
    assert_eq(cm_t('note_usage'), cm_last_text($log));
    $ins = $pdo->prepare('INSERT INTO cm_notes (chat_id, name, body, by_id, updated_at) VALUES (:c, :n, :b, 1, 0)');
    for ($i = 0; $i < 50; $i++) { $ins->execute([':c' => CM_CHAT, ':n' => 'n' . $i, ':b' => 'x']); }
    cm_do($o, "+заметка новая\nтекст");
    assert_eq(cm_t('notes_full', ['max' => 50]), cm_last_text($log));
    cm_do($o, "+заметка n1\nновый текст");
    assert_eq(cm_t('note_saved', ['name' => 'n1']), cm_last_text($log), 'старую обновить можно и на лимите');
});

test('когда: через, дата по Москве, прошедшая дата — через год, пределы', function () {
    assert_eq(CM_NOW + 7200, cm_parse_when('через 2ч', CM_NOW));
    assert_eq(-1, cm_parse_when('через 30с', CM_NOW));
    assert_eq(-1, cm_parse_when('через навсегда', CM_NOW));
    $d = new DateTime('@' . CM_NOW);
    $d->setTimezone(new DateTimeZone('Europe/Moscow'));
    $t = clone $d;
    $t->modify('+1 day');
    $t->setTime(10, 0);
    assert_eq($t->getTimestamp(), cm_parse_when($t->format('d.m') . ' 10:00', CM_NOW));
    $y = clone $d;
    $y->modify('-1 day');
    $y->setTime(10, 0);
    $y2 = clone $y;
    $y2->modify('+1 year');
    assert_eq($y2->getTimestamp(), cm_parse_when($y->format('d.m') . ' 10:00', CM_NOW));
    assert_eq(-1, cm_parse_when($y->format('d.m.Y') . ' 10:00', CM_NOW), 'прошлое с годом');
    assert_eq(-1, cm_parse_when('31.02 10:00', CM_NOW));
    assert_eq(-1, cm_parse_when('25.12 25:00', CM_NOW));
    assert_eq(-1, cm_parse_when('завтра', CM_NOW));
});

test('таймер: добавить, список, отменить, не больше пяти', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $o = cm_ctx_for($pdo, $log, CM_OWNER);
    cm_do($o, "таймер через 2ч\nмут @vasya 1ч");
    $row = $pdo->query('SELECT id, run_at, body, by_id, done_at FROM cm_timers')->fetch(PDO::FETCH_ASSOC);
    assert_eq([1, CM_NOW + 7200, 'мут @vasya 1ч', CM_OWNER, 0],
        [(int)$row['id'], (int)$row['run_at'], $row['body'], (int)$row['by_id'], (int)$row['done_at']]);
    assert_eq(cm_t('timer_added', ['id' => 1, 'date' => cm_date(CM_NOW + 7200)]), cm_last_text($log));
    cm_do($o, 'таймер через 2ч');
    assert_eq(cm_t('timer_usage'), cm_last_text($log));
    cm_do($o, 'таймеры');
    assert_eq(cm_t('timers_head') . "\n#1 · " . cm_date(CM_NOW + 7200) . ' · мут @vasya 1ч', cm_last_text($log));
    for ($i = 0; $i < 4; $i++) { cm_do($o, "таймер через 3ч\nтекст $i"); }
    cm_do($o, "таймер через 4ч\nлишний");
    assert_eq(cm_t('timer_full', ['max' => 5]), cm_last_text($log));
    cm_do($o, '-таймер 1');
    assert_eq(CM_NOW, (int)$pdo->query('SELECT done_at FROM cm_timers WHERE id = 1')->fetchColumn());
    cm_do($o, '-таймер #1');
    assert_eq(cm_t('timer_missing', ['id' => 1]), cm_last_text($log));
});

run_tests();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/c/xampp/php/php.exe tests/cm_notes_test.php`
Expected: FAIL — `Call to undefined function cm_cmd_note_add()`.

- [ ] **Step 3: Заметки**

Write `public_html/api/lib/cm/notes.php`:

```php
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
```

- [ ] **Step 4: Таймеры**

Write `public_html/api/lib/cm/timers.php`:

```php
<?php
// Таймеры: «таймер через 2ч» или «таймер 25.12 18:00» (МСК), со второй
// строки — команда или текст. Срабатывают в cron (cm_timers_tick, cron.php)
// и перепроверяют ранг автора: снятый модератор не выстрелит из прошлого.

const CM_TIMERS_MAX      = 5;
const CM_TIMER_MAX_AHEAD = 31622400;

// Время запуска или -1. Дата без года, которая уже прошла, — в следующем году.
function cm_parse_when(string $args, int $now): int {
    $a = trim(cm_norm($args));
    if (preg_match('~^через\s+(.+)$~u', $a, $m)) {
        $rest = $m[1];
        $t = cm_take_term($rest);
        if ($t === null || $t <= 0 || trim($rest) !== '') { return -1; }
        $at = $now + $t;
    } elseif (preg_match('~^(\d{1,2})\.(\d{1,2})(?:\.(\d{4}))?\s+(\d{1,2}):(\d{2})$~', $a, $m)) {
        $tz = new DateTimeZone(CM_TZ);
        $hasYear = isset($m[3]) && $m[3] !== '';
        $nowD = new DateTime('@' . $now);
        $nowD->setTimezone($tz);
        $year = $hasYear ? (int)$m[3] : (int)$nowD->format('Y');
        if (!checkdate((int)$m[2], (int)$m[1], $year) || (int)$m[4] > 23 || (int)$m[5] > 59) { return -1; }
        $d = new DateTime(sprintf('%04d-%02d-%02d %02d:%02d:00', $year, (int)$m[2], (int)$m[1], (int)$m[4], (int)$m[5]), $tz);
        if (!$hasYear && $d->getTimestamp() <= $now) { $d->modify('+1 year'); }
        $at = $d->getTimestamp();
    } else {
        return -1;
    }
    return ($at - $now < 60 || $at - $now > CM_TIMER_MAX_AHEAD) ? -1 : $at;
}

function cm_cmd_timer_add(array $ctx, array $cmd): void {
    $at = cm_parse_when($cmd['args'], (int)$ctx['now']);
    $body = trim($cmd['body']);
    if ($at < 0 || $body === '') {
        cm_reply($ctx, cm_t('timer_usage'));
        return;
    }
    $pdo = $ctx['pdo'];
    $chatId = (int)$ctx['chat']['chat_id'];
    $st = $pdo->prepare('SELECT COUNT(*) FROM cm_timers WHERE chat_id = :c AND done_at = 0');
    $st->execute([':c' => $chatId]);
    if ((int)$st->fetchColumn() >= CM_TIMERS_MAX) {
        cm_reply($ctx, cm_t('timer_full', ['max' => CM_TIMERS_MAX]));
        return;
    }
    $pdo->prepare('INSERT INTO cm_timers (chat_id, run_at, body, by_id, created_at, done_at) VALUES (:c, :r, :b, :by, :n, 0)')
        ->execute([':c' => $chatId, ':r' => $at, ':b' => mb_substr($body, 0, 4000), ':by' => (int)$ctx['actor'], ':n' => (int)$ctx['now']]);
    cm_reply($ctx, cm_t('timer_added', ['id' => (int)$pdo->lastInsertId(), 'date' => cm_date($at)]), false);
}

function cm_cmd_timer_list(array $ctx, array $cmd): void {
    $st = $ctx['pdo']->prepare('SELECT id, run_at, body FROM cm_timers WHERE chat_id = :c AND done_at = 0 ORDER BY run_at');
    $st->execute([':c' => $ctx['chat']['chat_id']]);
    $lines = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $first = explode("\n", (string)$r['body'])[0];
        $lines[] = '#' . (int)$r['id'] . ' · ' . cm_date((int)$r['run_at']) . ' · ' . mb_substr($first, 0, 40);
    }
    cm_reply($ctx, $lines ? cm_t('timers_head') . "\n" . implode("\n", $lines) : cm_t('timers_empty'), false);
}

function cm_cmd_timer_del(array $ctx, array $cmd): void {
    $id = preg_match('~^#?(\d{1,9})$~', trim($cmd['args']), $m) ? (int)$m[1] : 0;
    $st = $ctx['pdo']->prepare('UPDATE cm_timers SET done_at = :n WHERE id = :id AND chat_id = :c AND done_at = 0');
    $st->execute([':n' => $ctx['now'], ':id' => $id, ':c' => $ctx['chat']['chat_id']]);
    cm_reply($ctx, cm_t($st->rowCount() > 0 ? 'timer_removed' : 'timer_missing', ['id' => $id]), $st->rowCount() === 0);
}
```

Edit `public_html/api/lib/cm/cm.php`: массив частей → `[..., 'cleanup', 'notes', 'timers']`.

- [ ] **Step 5: Run test to verify it passes**

Run: `/c/xampp/php/php.exe tests/cm_notes_test.php`
Expected: `0 failures`.

- [ ] **Step 6: Commit**

```bash
git add public_html/api/lib/cm/notes.php public_html/api/lib/cm/timers.php public_html/api/lib/cm/cm.php tests/cm_notes_test.php
git commit -m "feat(cm): заметки по #имени и команды таймеров" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git show --stat HEAD
```

---

### Task 12: Роутер, вебхук, cron

**Files:**
- Create: `public_html/api/lib/cm/router.php`, `public_html/api/lib/cm/cron.php`, `public_html/api/cm_cron.php`
- Modify: `public_html/api/lib/cm/cm.php` — итоговый массив частей
- Modify: `public_html/api/tg_webhook.php` — маршрут в модуль (полный текст ниже)
- Modify: `public_html/api/lib/telegram.php:1017-1040` — `tg_setup_webhook()`
- Modify: `public_html/.htaccess` — 404 на `api/cm_cron.php`
- Modify: `tests/telegram_test.php:539` и рядом — новые ожидания
- Test: `tests/cm_router_test.php`

**Interfaces:**
- Consumes: Task 1–11; `tg_finish_response()`, `tg_http()`, `tg_config()`, `tg_enabled()` из `api/lib/telegram.php`.
- Produces:
  - `CM_PRIVATE_HANDLERS`, `cm_wants_update(array $update, string $botName): bool`
  - `cm_handle_update(PDO $pdo, array $cfg, callable $send, array $update, int $now): void`
  - `cm_on_message(...)`, `cm_on_private(...)`, `cm_on_callback(...)`, `cm_run_command(array $ctx, array $cmd): bool` (`true` — сообщение было командой и обработано; `false` — не команда, дальше фильтры)
  - обработчики `help`, `whoami`, `id`
  - `cm_timers_tick(PDO $pdo, array $cfg, callable $send, int $now): int`, `cm_cron_tick(PDO $pdo, array $cfg, callable $send, int $now): array` (`['timers' => int, 'deleted' => int]`)

- [ ] **Step 1: Write the failing test**

Write `tests/cm_router_test.php`:

```php
<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/telegram.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';

// Сквозной путь апдейта: api/lib/cm/router.php, cron.php, установка вебхука.

function rt_run(PDO $pdo, array $msg, array &$log, array $answers = [], int $now = CM_NOW, string $key = 'message'): void {
    cm_handle_update($pdo, cm_cfg(), cm_rec($log, $answers), ['update_id' => 1, $key => $msg], $now);
}

test('чей апдейт', function () {
    $g = cm_gmsg(7, 'привет');
    $p = ['message_id' => 1, 'chat' => ['id' => 500, 'type' => 'private'], 'from' => ['id' => 500], 'text' => 'привет'];
    assert_eq(true, cm_wants_update(['message' => $g], CM_BOT));
    assert_eq(true, cm_wants_update(['edited_message' => $g], CM_BOT));
    assert_eq(false, cm_wants_update(['message' => $p], CM_BOT));
    assert_eq(true, cm_wants_update(['message' => ['text' => '+спамер 123456789'] + $p], CM_BOT));
    assert_eq(false, cm_wants_update(['message' => ['text' => '/start abc'] + $p], CM_BOT));
    assert_eq(true, cm_wants_update(['callback_query' => ['data' => 'cm:r:1:d']], CM_BOT));
    assert_eq(false, cm_wants_update(['callback_query' => ['data' => 'gw:join:5']], CM_BOT));
    assert_eq(false, cm_wants_update(['channel_post' => $g], CM_BOT));
    assert_eq(false, cm_wants_update(['my_chat_member' => []], CM_BOT));
});

test('модуль выключен или нет таблиц — тишина', function () {
    $log = [];
    cm_handle_update(cm_test_pdo(), cm_cfg(['cm_enabled' => false]), cm_rec($log), ['message' => cm_gmsg(7, 'evil.ru')], CM_NOW);
    cm_handle_update(test_db(), cm_cfg(), cm_rec($log), ['message' => cm_gmsg(7, 'evil.ru')], CM_NOW);
    assert_eq([], $log);
});

test('ссылка от участника удаляется молча, от админа Telegram — нет', function () {
    $pdo = cm_test_pdo();
    $log = [];
    rt_run($pdo, cm_gmsg(7, 'зайди evil.ru'), $log);
    rt_run($pdo, cm_gmsg(CM_ADMIN, 'зайди evil.ru'), $log);
    assert_eq(1, count(cm_calls($log, 'deleteMessage')));
    assert_eq(0, count(cm_calls($log, 'sendMessage')));
    assert_eq(2, (int)$pdo->query('SELECT COUNT(*) FROM cm_msgs')->fetchColumn(), 'сообщения записаны');
});

test('без ранга: с префиксом — отказ, без префикса — молча, а фильтры работают', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 20, 'vasya');
    rt_run($pdo, cm_gmsg(7, '!бан @vasya'), $log);
    assert_eq(cm_t('no_rank', ['need' => 3]), cm_last_text($log));
    rt_run($pdo, cm_gmsg(7, 'бан @vasya evil.ru'), $log);
    assert_eq(0, count(cm_calls($log, 'banChatMember')));
    assert_eq(1, count(cm_calls($log, 'deleteMessage')), 'ссылка в «команде» без прав удалена');
    assert_eq(1, count(cm_calls($log, 'sendMessage')));
});

test('модератор без префикса: с целью — команда, без цели — обычная речь', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_lvl($pdo, 10, 1);
    cm_person($pdo, 20, 'vasya');
    rt_run($pdo, cm_gmsg(10, 'мут @vasya 10м'), $log);
    assert_eq(CM_NOW + 600, cm_calls($log, 'restrictChatMember')[0][1]['until_date']);
    $n = count(cm_calls($log, 'sendMessage'));
    rt_run($pdo, cm_gmsg(10, 'мут бы ему'), $log);
    assert_eq($n, count(cm_calls($log, 'sendMessage')));
});

test('команды уровня 0 без префикса — только ровно команда', function () {
    $pdo = cm_test_pdo();
    $log = [];
    rt_run($pdo, cm_gmsg(CM_OWNER, "+правила\nБез спама"), $log);
    rt_run($pdo, cm_gmsg(7, 'правила'), $log);
    assert_eq('Без спама', cm_last_text($log));
    $n = count(cm_calls($log, 'sendMessage'));
    rt_run($pdo, cm_gmsg(7, 'правила нужны всем'), $log);
    assert_eq($n, count(cm_calls($log, 'sendMessage')));
});

test('дк 6 выключает команду даже создателю', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 20, 'vasya');
    rt_run($pdo, cm_gmsg(CM_OWNER, 'дк мут 6'), $log);
    rt_run($pdo, cm_gmsg(CM_OWNER, '!мут @vasya'), $log);
    assert_eq(cm_t('cmd_off'), cm_last_text($log));
    assert_eq(0, count(cm_calls($log, 'restrictChatMember')));
});

test('спамер из базы: сообщение удалено, бан', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $pdo->exec("INSERT INTO cm_spam (user_id, reason, added_by, added_at) VALUES (66, 'скам', '900', 0)");
    rt_run($pdo, cm_gmsg(66, 'привет'), $log);
    assert_eq([1, 66], [count(cm_calls($log, 'deleteMessage')), cm_calls($log, 'banChatMember')[0][1]['user_id']]);
});

test('от имени группы — не трогаем, от имени чужого канала — только удаляем', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $bot = ['id' => 136817688, 'is_bot' => true, 'first_name' => 'Channel'];
    rt_run($pdo, cm_gmsg(0, 'evil.ru', ['from' => $bot, 'sender_chat' => ['id' => CM_CHAT, 'type' => 'supergroup']]), $log);
    assert_eq(0, count(cm_calls($log, 'deleteMessage')));
    rt_run($pdo, cm_gmsg(0, 'evil.ru', ['from' => $bot, 'sender_chat' => ['id' => -1009, 'type' => 'channel']]), $log);
    assert_eq(1, count(cm_calls($log, 'deleteMessage')));
    assert_eq(0, count(cm_calls($log, 'banChatMember')) + count(cm_calls($log, 'restrictChatMember')));
});

test('правка: ссылку ловим, команду не выполняем', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_lvl($pdo, 10, 1);
    cm_person($pdo, 20, 'vasya');
    rt_run($pdo, cm_gmsg(7, 'дописал evil.ru'), $log, [], CM_NOW, 'edited_message');
    assert_eq(1, count(cm_calls($log, 'deleteMessage')));
    rt_run($pdo, cm_gmsg(10, 'мут @vasya'), $log, [], CM_NOW, 'edited_message');
    assert_eq(0, count(cm_calls($log, 'restrictChatMember')));
});

test('заметка по #имени, вход и переезд группы', function () {
    $pdo = cm_test_pdo();
    $log = [];
    rt_run($pdo, cm_gmsg(CM_OWNER, "+заметка faq\nОтвет"), $log);
    rt_run($pdo, cm_gmsg(7, '#faq'), $log);
    assert_eq('Ответ', cm_last_text($log));
    rt_run($pdo, cm_gmsg(CM_OWNER, "+приветствие\nПривет, {имя}!"), $log);
    rt_run($pdo, cm_gmsg(7, '', ['new_chat_members' => [['id' => 70, 'first_name' => 'Гость']]]), $log);
    assert_eq('Привет, Гость!', cm_last_text($log));
    rt_run($pdo, cm_gmsg(7, '', ['migrate_to_chat_id' => -1009999]), $log);
    assert_eq(null, cm_chat_row($pdo, CM_CHAT));
    assert_eq('Привет, {имя}!', cm_chat_row($pdo, -1009999)['settings']['welcome']);
});

test('кнопка: Telegram всегда получает ответ, действие — по данным', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $reply = ['reply_to_message' => ['message_id' => 70, 'from' => ['id' => 20, 'first_name' => 'Нарушитель']]];
    rt_run($pdo, cm_gmsg(30, 'репорт спам', $reply), $log);
    $chat = ['id' => CM_CHAT, 'type' => 'supergroup', 'title' => 'Тестовый чат'];
    $q = ['id' => 'q1', 'from' => ['id' => CM_OWNER, 'first_name' => 'Owner'], 'data' => 'cm:r:1:x',
          'message' => ['message_id' => 9000, 'chat' => $chat, 'text' => 'карточка']];
    cm_handle_update($pdo, cm_cfg(), cm_rec($log), ['callback_query' => $q], CM_NOW);
    $ans = cm_calls($log, 'answerCallbackQuery');
    assert_eq(['q1', cm_t('report_handled')], [$ans[0][1]['callback_query_id'], $ans[0][1]['text']]);
    assert_eq('rejected', $pdo->query('SELECT status FROM cm_reports')->fetchColumn());
    $q['data'] = 'cm:zzz';
    cm_handle_update($pdo, cm_cfg(), cm_rec($log), ['callback_query' => $q], CM_NOW);
    assert_eq('', cm_calls($log, 'answerCallbackQuery')[1][1]['text']);
});

test('личка: +спамер от сотрудника', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $pdo->exec('INSERT INTO tg_links (user_id, chat_id, linked_at) VALUES (900, 500, 0)');
    $p = ['message_id' => 1, 'chat' => ['id' => 500, 'type' => 'private'], 'from' => ['id' => 500, 'first_name' => 'Мод'], 'text' => '+спамер 123456789'];
    rt_run($pdo, $p, $log);
    assert_eq(true, cm_spam_has($pdo, 123456789));
    assert_eq(500, cm_calls($log, 'sendMessage')[0][1]['chat_id']);
});

test('помощь, кто я, id', function () {
    $pdo = cm_test_pdo();
    $log = [];
    rt_run($pdo, cm_gmsg(7, 'помощь'), $log);
    assert_eq(cm_t('help'), cm_last_text($log));
    rt_run($pdo, cm_gmsg(7, 'кто я'), $log);
    assert_eq('Ваш ранг: 0 — участник. Варны: 0/3.', cm_last_text($log));
    rt_run($pdo, cm_gmsg(7, 'id', ['reply_to_message' => ['message_id' => 5, 'from' => ['id' => 20, 'first_name' => 'Вася']]]), $log);
    assert_eq(true, strpos(cm_last_text($log), 'Вася: 20') !== false);
});

test('таймер выполняет команду от имени автора и заново проверяет ранг', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_chat_ensure($pdo, ['id' => CM_CHAT, 'type' => 'supergroup', 'title' => 'Тестовый чат'], CM_NOW);
    cm_lvl($pdo, 10, 1);
    cm_person($pdo, 20, 'vasya');
    $ins = $pdo->prepare('INSERT INTO cm_timers (chat_id, run_at, body, by_id, created_at, done_at) VALUES (:c, :r, :b, 10, 0, 0)');
    $ins->execute([':c' => CM_CHAT, ':r' => CM_NOW - 1, ':b' => 'мут @vasya 10м']);
    $ins->execute([':c' => CM_CHAT, ':r' => CM_NOW - 1, ':b' => 'Всем привет!']);
    $ins->execute([':c' => CM_CHAT, ':r' => CM_NOW + 999, ':b' => 'рано']);
    assert_eq(2, cm_timers_tick($pdo, cm_cfg(), cm_rec($log), CM_NOW));
    assert_eq(1, count(cm_calls($log, 'restrictChatMember')));
    assert_eq(true, in_array('Всем привет!', cm_texts($log), true));
    assert_eq(0, cm_timers_tick($pdo, cm_cfg(), cm_rec($log), CM_NOW), 'второй раз не срабатывает');
    cm_lvl($pdo, 10, 0);
    $ins->execute([':c' => CM_CHAT, ':r' => CM_NOW - 1, ':b' => 'мут @vasya 10м']);
    cm_timers_tick($pdo, cm_cfg(), cm_rec($log), CM_NOW);
    assert_eq(1, count(cm_calls($log, 'restrictChatMember')), 'снятый модератор не стреляет');
    assert_eq(cm_t('no_rank', ['need' => 1]), cm_last_text($log));
});

test('cron: таймеры и очередь', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_queue_add($pdo, CM_CHAT, 1, CM_NOW - 1);
    assert_eq(['timers' => 0, 'deleted' => 1], cm_cron_tick($pdo, cm_cfg(), cm_rec($log), CM_NOW));
});

test('вебхук: правки сообщений и право удалять в группах', function () {
    $log = [];
    tg_setup_webhook(cm_rec($log), '123456789:AAHfakeTokenForTestsOnly_abcdefghij');
    $hook = cm_calls($log, 'setWebhook')[0][1];
    assert_eq(true, in_array('edited_message', $hook['allowed_updates'], true));
    assert_eq(5, $hook['max_connections']);
    $rights = cm_calls($log, 'setMyDefaultAdministratorRights');
    assert_eq(true, $rights[1][1]['rights']['can_delete_messages']);
    assert_eq(false, isset($rights[0][1]['rights']['can_delete_messages']), 'каналам не нужно');
});

run_tests();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/c/xampp/php/php.exe tests/cm_router_test.php`
Expected: FAIL — `Call to undefined function cm_wants_update()`.

- [ ] **Step 3: Роутер**

Write `public_html/api/lib/cm/router.php`:

```php
<?php
// Разбор апдейта и запуск команд. Вызывается из api/tg_webhook.php уже после
// ответа Telegram: 200 ушёл сразу, вызовы Telegram идут следом.

// В личке бот понимает только команды базы спамеров (для сотрудников сайта),
// остальное в личке — уведомления, как раньше (tg_handle_update).
const CM_PRIVATE_HANDLERS = ['spam_add', 'spam_del', 'spam_check'];

function cm_wants_update(array $update, string $botName): bool {
    foreach (['message', 'edited_message'] as $k) {
        $m = $update[$k] ?? null;
        if (!is_array($m)) { continue; }
        $type = (string)($m['chat']['type'] ?? '');
        if ($type === 'group' || $type === 'supergroup') { return true; }
        if ($k === 'message' && $type === 'private') {
            $cmd = cm_parse((string)($m['text'] ?? ''), $botName);
            return $cmd !== null && in_array($cmd['handler'], CM_PRIVATE_HANDLERS, true);
        }
        return false;
    }
    $q = $update['callback_query'] ?? null;
    return is_array($q) && strncmp((string)($q['data'] ?? ''), 'cm:', 3) === 0;
}

function cm_handle_update(PDO $pdo, array $cfg, callable $send, array $update, int $now): void {
    if (!cm_enabled($cfg) || !cm_ready($pdo)) { return; }
    if (is_array($update['callback_query'] ?? null)) {
        cm_on_callback($pdo, $cfg, $send, $update['callback_query'], $now);
        return;
    }
    $edited = !is_array($update['message'] ?? null);
    $msg = $edited ? ($update['edited_message'] ?? null) : $update['message'];
    if (!is_array($msg) || !is_array($msg['chat'] ?? null)) { return; }
    if (($msg['chat']['type'] ?? '') === 'private') {
        if (!$edited) { cm_on_private($pdo, $cfg, $send, $msg, $now); }
        return;
    }
    cm_on_message($pdo, $cfg, $send, $msg, $edited, $now);
}

/**
 * Сообщение группы:
 *  0. от имени этой группы или автопересылка своего канала — не трогаем;
 *     от имени чужого канала — только удаляем (канал не замутить);
 *  1. запомнить автора;
 *  2. спамер из базы — бан;
 *  3. команда — выполнить;
 *  4. фильтры по старшинству;
 *  5. «#имя» — заметка.
 * Правку проверяют только фильтры: команду из правки не выполняем.
 */
function cm_on_message(PDO $pdo, array $cfg, callable $send, array $msg, bool $edited, int $now): void {
    $chatId = (int)($msg['chat']['id'] ?? 0);
    if (isset($msg['migrate_to_chat_id'])) {
        cm_migrate($pdo, $chatId, (int)$msg['migrate_to_chat_id']);
        return;
    }
    if (isset($msg['migrate_from_chat_id'])) {
        cm_migrate($pdo, (int)$msg['migrate_from_chat_id'], $chatId);
        return;
    }
    $chat = cm_chat_ensure($pdo, $msg['chat'], $now);
    $ctx = cm_ctx($pdo, $cfg, $send, $chat, $msg, $now);
    $ctx['edited'] = $edited;
    cm_admins_refresh($ctx);
    if (!empty($msg['new_chat_members'])) {
        cm_on_join($ctx, (array)$msg['new_chat_members']);
        return;
    }
    if (is_array($msg['left_chat_member'] ?? null)) {
        cm_on_left($ctx, $msg['left_chat_member']);
        return;
    }
    $sender = $msg['sender_chat'] ?? null;
    if (is_array($sender)) {
        if ((int)($sender['id'] ?? 0) === $chatId || !empty($msg['is_automatic_forward'])) { return; }
        if (cm_filter_hit($ctx, $msg) !== '') {
            $send('deleteMessage', ['chat_id' => $chatId, 'message_id' => (int)($msg['message_id'] ?? 0)]);
        }
        return;
    }
    $from = $msg['from'] ?? null;
    if (!is_array($from) || !empty($from['is_bot'])) { return; }
    $uid = (int)($from['id'] ?? 0);
    $ctx['rank'] = cm_rank($ctx, $uid);
    if (!$edited) {
        cm_user_touch($pdo, $from, $now);
        cm_member_touch($pdo, $chatId, $uid, $now);
        cm_msg_log($pdo, $chatId, (int)($msg['message_id'] ?? 0), $uid, cm_text_of($msg), $now);
    }
    if (!empty($chat['settings']['filters']['spam']) && !cm_exempt($ctx, $uid) && cm_spam_has($pdo, $uid)) {
        cm_apply_actions($ctx, $uid, (int)($msg['message_id'] ?? 0), cm_trigger_actions($chat, 'spam'), cm_t('reason_spam'), 0);
        return;
    }
    if (!$edited && isset($msg['text'])) {
        $cmd = cm_parse((string)$msg['text'], $ctx['bot']);
        if ($cmd !== null && cm_run_command($ctx, $cmd)) { return; }
    }
    if (!cm_exempt($ctx, $uid)) {
        $hit = cm_filter_hit($ctx, $msg);
        if ($hit !== '') {
            cm_filter_act($ctx, $hit);
            return;
        }
    }
    if (!$edited) { cm_note_try($ctx); }
}

/**
 * Выполнить команду с проверкой доступа. false — это не команда (без префикса
 * и не подходит под правила), сообщение идёт дальше в фильтры:
 *  - команда уровня 0 без префикса — только если строка ровно команда;
 *  - команда с целью без префикса — только если цель нашлась;
 *  - без ранга и без префикса — молча, как обычная речь.
 */
function cm_run_command(array $ctx, array $cmd): bool {
    if (!$cmd['prefixed'] && $cmd['exact'] && ($cmd['args'] !== '' || trim($cmd['body']) !== '')) { return false; }
    if (!$cmd['prefixed'] && $cmd['target']) {
        $a = $cmd['args'];
        if (cm_target($ctx['pdo'], $ctx['msg'], $a) === null) { return false; }
    }
    $fn = 'cm_cmd_' . $cmd['handler'];
    if (!function_exists($fn)) { return false; }
    $need = cm_need_level($ctx['chat'], $cmd['section'], (int)$cmd['level']);
    if ($need >= 6) {
        if ($cmd['prefixed']) { cm_reply($ctx, cm_t('cmd_off')); }
        return (bool)$cmd['prefixed'];
    }
    if ((int)$ctx['rank'] < $need) {
        if ($cmd['prefixed']) { cm_reply($ctx, cm_t('no_rank', ['need' => $need])); }
        return (bool)$cmd['prefixed'];
    }
    if (!empty($ctx['timer']) && $cmd['handler'] === 'timer_add') { return true; }
    $fn($ctx, $cmd);
    return true;
}

function cm_on_private(PDO $pdo, array $cfg, callable $send, array $msg, int $now): void {
    $cmd = cm_parse((string)($msg['text'] ?? ''), ltrim((string)($cfg['tg_bot_name'] ?? ''), '@'));
    if ($cmd === null || !in_array($cmd['handler'], CM_PRIVATE_HANDLERS, true)) { return; }
    $ctx = cm_ctx($pdo, $cfg, $send, cm_private_chat((int)$msg['chat']['id']), $msg, $now);
    $fn = 'cm_cmd_' . $cmd['handler'];
    $fn($ctx, $cmd);
}

// Кнопки «cm:»: r — репорт, k — массовый кик, o — передача создателя.
// answerCallbackQuery уходит всегда, иначе у человека крутятся часики.
function cm_on_callback(PDO $pdo, array $cfg, callable $send, array $q, int $now): void {
    $answer = '';
    $qm = is_array($q['message'] ?? null) ? $q['message'] : null;
    if ($qm !== null && is_array($qm['chat'] ?? null) && (int)($qm['chat']['id'] ?? 0) < 0) {
        $chat = cm_chat_ensure($pdo, $qm['chat'], $now);
        $ctx = cm_ctx($pdo, $cfg, $send, $chat, $qm, $now);
        $from = is_array($q['from'] ?? null) ? $q['from'] : [];
        $ctx['actor'] = (int)($from['id'] ?? 0);
        cm_user_touch($pdo, $from, $now);
        cm_admins_refresh($ctx);
        $ctx['rank'] = cm_rank($ctx, $ctx['actor']);
        $p = explode(':', (string)($q['data'] ?? ''));
        $kind = $p[1] ?? '';
        if ($kind === 'r' && count($p) === 4) {
            $answer = cm_report_callback($ctx, $q, (int)$p[2], (string)$p[3]);
        } elseif ($kind === 'k' && count($p) === 5) {
            $answer = cm_mass_callback($ctx, $q, (string)$p[2], (int)$p[3], (int)$p[4]);
        } elseif ($kind === 'o' && count($p) === 4) {
            $answer = cm_owner_callback($ctx, $q, (int)$p[2], (int)$p[3]);
        }
    }
    $send('answerCallbackQuery', ['callback_query_id' => (string)($q['id'] ?? ''), 'text' => mb_substr($answer, 0, 200)]);
}

// --------------------------------------------------------------------------
//  Общие команды
// --------------------------------------------------------------------------

function cm_cmd_help(array $ctx, array $cmd): void {
    cm_reply($ctx, cm_t('help'), false);
}

function cm_cmd_whoami(array $ctx, array $cmd): void {
    $r = (int)$ctx['rank'];
    cm_reply($ctx, cm_t('whoami', ['n' => $r, 'rank' => CM_RANK_NAMES[$r], 'warns' => cm_warns_active($ctx, (int)$ctx['actor']),
        'limit' => (int)$ctx['chat']['settings']['warns']['limit']]));
}

function cm_cmd_id(array $ctx, array $cmd): void {
    $text = cm_t('id_text', ['chat' => (int)$ctx['chat']['chat_id'], 'me' => (int)$ctx['actor']]);
    $r = cm_reply_of($ctx['msg']);
    if ($r !== null && is_array($r['from'] ?? null)) {
        $text .= "\n" . cm_t('id_target', ['name' => cm_display_name($r['from']), 'id' => (int)($r['from']['id'] ?? 0)]);
    }
    cm_reply($ctx, $text);
}
```

- [ ] **Step 4: Тик cron**

Write `public_html/api/lib/cm/cron.php`:

```php
<?php
// Работа раз в минуту (api/cm_cron.php): таймеры и автоудаление ответов.

// Таймер выполняется от имени автора с его ТЕКУЩИМ рангом. Строка
// помечается выполненной до запуска: второй cron, начатый внахлёст, её не
// возьмёт.
function cm_timers_tick(PDO $pdo, array $cfg, callable $send, int $now): int {
    $st = $pdo->prepare('SELECT * FROM cm_timers WHERE done_at = 0 AND run_at <= :n ORDER BY run_at, id LIMIT 50');
    $st->execute([':n' => $now]);
    $claim = $pdo->prepare('UPDATE cm_timers SET done_at = :n WHERE id = :id AND done_at = 0');
    $botName = ltrim((string)($cfg['tg_bot_name'] ?? ''), '@');
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $claim->execute([':n' => $now, ':id' => (int)$t['id']]);
        if ($claim->rowCount() === 0) { continue; }
        $chat = cm_chat_row($pdo, (int)$t['chat_id']);
        if ($chat === null || $chat['active'] !== 1) { continue; }
        $by = (int)$t['by_id'];
        $body = (string)$t['body'];
        $msg = ['message_id' => 0, 'chat' => ['id' => $chat['chat_id'], 'type' => 'supergroup', 'title' => $chat['title']],
                'from' => ['id' => $by, 'first_name' => cm_user_name($pdo, $by)], 'text' => $body];
        $ctx = cm_ctx($pdo, $cfg, $send, $chat, $msg, $now);
        $ctx['timer'] = true;
        cm_admins_refresh($ctx);
        $ctx['rank'] = cm_rank($ctx, $by);
        $cmd = cm_parse($body, $botName);
        if ($cmd !== null) {
            $cmd['prefixed'] = true;
            cm_run_command($ctx, $cmd);
        } else {
            cm_say($ctx, (int)$chat['chat_id'], $body);
        }
        $n++;
    }
    return $n;
}

function cm_cron_tick(PDO $pdo, array $cfg, callable $send, int $now): array {
    return ['timers' => cm_timers_tick($pdo, $cfg, $send, $now), 'deleted' => cm_queue_tick($pdo, $send, $now)];
}
```

Edit `public_html/api/lib/cm/cm.php`: итоговый массив частей —

```php
foreach (['db', 'texts', 'parse', 'send', 'queue', 'ranks', 'punish', 'words', 'filters', 'spam',
          'welcome', 'reports', 'cleanup', 'notes', 'timers', 'router', 'cron'] as $cm_part) {
```

- [ ] **Step 5: Run router test**

Run: `/c/xampp/php/php.exe tests/cm_router_test.php`
Expected: падает только последний тест (`вебхук: правки сообщений…`) — `tg_setup_webhook` ещё старый. Остальное — `ok`.

- [ ] **Step 6: Установка вебхука**

Edit `public_html/api/lib/telegram.php`, в `tg_setup_webhook()`:

Заменить
```php
        // callback_query — нажали «Участвовать» под постом розыгрыша
        // (api/lib/gw_tg.php).
        'allowed_updates'      => ['message', 'my_chat_member', 'chat_member', 'callback_query'],
```
на
```php
        // callback_query — нажали «Участвовать» под постом розыгрыша
        // (api/lib/gw_tg.php) или кнопку чат-менеджера (api/lib/cm/).
        // edited_message — чат-менеджер проверяет и правки: спамеры
        // дописывают ссылку уже после отправки.
        'allowed_updates'      => ['message', 'edited_message', 'my_chat_member', 'chat_member', 'callback_query'],
```

Заменить
```php
    $send('setMyDefaultAdministratorRights', ['rights' => tg_default_rights(false)]);
```
на
```php
    // В группах чат-менеджеру нужно ещё удалять сообщения. Розыгрышам это
    // право не нужно, поэтому в TG_CHANNEL_RIGHTS его нет.
    $send('setMyDefaultAdministratorRights', ['rights' => tg_default_rights(false) + ['can_delete_messages' => true]]);
```

Edit `tests/telegram_test.php`, тест «установка вебхука отдаёт адрес сайта и секрет»:

Заменить
```php
    assert_eq(['message', 'my_chat_member', 'chat_member', 'callback_query'], $log[0][1]['allowed_updates']);
```
на
```php
    assert_eq(['message', 'edited_message', 'my_chat_member', 'chat_member', 'callback_query'], $log[0][1]['allowed_updates']);
```
и после строки `assert_eq(false, isset($rights[1][1]['rights']['can_post_messages']), 'в группах публикаций нет');` добавить
```php
    assert_eq(true, $rights[1][1]['rights']['can_delete_messages'], 'чат-менеджер удаляет сообщения в группах');
```

- [ ] **Step 7: Вебхук**

Replace whole `public_html/api/tg_webhook.php` (Write; было 60 строк — сохранён весь прежний поток, добавлены подключение модуля, ранний маршрут и `cm_on_membership` в конце):

```php
<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/telegram.php';
require_once __DIR__ . '/lib/giveaway.php';
require_once __DIR__ . '/lib/cm/cm.php';

// Вебхук бота — сюда Telegram присылает всё, что люди пишут боту.
// Адрес и секрет сообщает Telegram кнопка на /admin/support (api/tg_setup.php).
//
// Без верного секрета в заголовке X-Telegram-Bot-Api-Secret-Token запрос
// отвергается: иначе любой, кто знает адрес, прислал бы «/start <код>» от
// имени произвольного chat_id.
//
// Отвечаем всегда 200 и сразу: на любой другой код Telegram повторяет
// доставку, и одна ошибка базы превращалась бы в очередь повторов. Ответ на
// сообщение уходит прямо в теле — Telegram выполняет его как вызов API.
//
// Сообщения групп и кнопки «cm:» — чат-менеджер (api/lib/cm/,
// docs/chat-manager.md): 200 уходит сразу, вызовы Telegram — после, ошибка
// модуля пишется в лог и остальное не задевает.

function tg_webhook_authorized(array $tg, array $server): bool {
    if (!tg_enabled($tg)) { return false; }
    $got = $server['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    return is_string($got) && $got !== '' && hash_equals(tg_webhook_secret($tg['token']), $got);
}

if (!defined('TESTING')) {
    header('Cache-Control: no-store');
    require_post();

    $tg = tg_config(app_config());
    if (!tg_webhook_authorized($tg, $_SERVER)) {
        json_out(['ok' => false], 403);
        exit;
    }

    $update = read_json_body();
    if (cm_wants_update($update, $tg['name'])) {
        json_out(['ok' => true]);
        tg_finish_response();
        try {
            cm_handle_update(db(), app_config(), tg_http($tg['token']), $update, time());
        } catch (Throwable $e) {
            error_log('cm: ' . $e->getMessage());
        }
        exit;
    }

    $reply = null;
    $invite = null;
    $left = [];
    $joined = null;
    try {
        // Нажали «Участвовать» под постом розыгрыша в канале — участие прямо
        // в Telegram, ответ всплывающим окном (api/lib/gw_tg.php).
        $joined = gw_tg_callback(db(), app_config(), tg_http($tg['token']), $update, time());
        // Прислали боту премиум-эмодзи — он отвечает их id для админки
        // розыгрышей (раздел «Премиум-эмодзи» в api/lib/giveaway.php).
        // «Забрать приз» и ник в Roblox от победителя — gw_tg_bot_reply().
        $reply = $joined['reply'] ?? gw_emoji_reply($update) ?? gw_tg_bot_reply(db(), $update, time())
            ?? tg_handle_update(db(), $update, time());
        // Бота добавили в канал или убрали — список каналов для условий
        // розыгрышей (api/lib/giveaway.php). Ссылку-приглашение приватного
        // канала просим уже после ответа Telegram. Добавившему бот сразу
        // пишет, каких прав ему не дали.
        $invite = gw_channel_update(db(), $update, time());
        $reply = $reply ?? gw_channel_reply($update, $tg['name']);
        // Кто-то вступил в канал по ссылке розыгрыша или вышел из канала —
        // учёт подписчиков и снятие участия у отписавшихся.
        $left = gw_member_update(db(), $update, time());
    } catch (Throwable $e) {
        error_log('tg webhook: ' . $e->getMessage());
    }
    // Новый участник меняет число на сайте — кеш выдачи розыгрышей сбрасывается.
    if (!empty($joined['after'])) { header('X-LiteSpeed-Purge: tag=giveaways'); }
    json_out($reply ?? ['ok' => true]);
    if ($joined) { gw_tg_after(db(), app_config(), $joined['after'], time()); }
    if ($invite !== null) { gw_after_channel(db(), app_config(), $invite, time()); }
    if ($left) { gw_after_member(db(), app_config(), $left, time()); }
    // Бота добавили в группу или убрали, человек вошёл или вышел — тот же
    // апдейт нужен и чат-менеджеру: знакомство с группой, приветствие.
    if (isset($update['my_chat_member']) || isset($update['chat_member'])) {
        tg_finish_response();
        try {
            cm_on_membership(db(), app_config(), tg_http($tg['token']), $update, time());
        } catch (Throwable $e) {
            error_log('cm member: ' . $e->getMessage());
        }
    }
}
```

Перед заменой сверить старый файл с этим текстом: всё, кроме помеченных добавлений, должно совпасть построчно (`git diff public_html/api/tg_webhook.php` после записи — только добавления и перенос `$update = read_json_body();` из `try` наверх).

- [ ] **Step 8: Запуск из cron и закрытие от браузера**

Write `public_html/api/cm_cron.php`:

```php
<?php
// Чат-менеджер раз в минуту: таймеры и автоудаление ответов бота
// (api/lib/cm/cron.php). Cron в cPanel:
//
//   * * * * * /usr/local/bin/php /home/maknemyt/public_html/api/cm_cron.php >/dev/null 2>&1
//
// Только из командной строки. Из браузера — 404 (его же отдаёт .htaccess).
// Проверяется наличие веб-запроса, а не PHP_SAPI === 'cli': в cPanel
// /usr/local/bin/php бывает CGI-сборкой (как в api/stock_pull.php).
// Ключ -v — напечатать итог прохода.

if (isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/telegram.php';
require_once __DIR__ . '/lib/cm/cm.php';

$lock = @fopen(sys_get_temp_dir() . '/maknemy-cm-cron.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) { exit(0); }

$cfg = app_config();
$tg = tg_config($cfg);
$res = ['ok' => false];
if (tg_enabled($tg) && cm_enabled($cfg) && cm_ready(db())) {
    try {
        $res = ['ok' => true] + cm_cron_tick(db(), $cfg, tg_http($tg['token']), time());
    } catch (Throwable $e) {
        error_log('cm cron: ' . $e->getMessage());
    }
}
if (in_array('-v', $argv ?? [], true)) {
    echo json_encode($res, JSON_UNESCAPED_UNICODE), PHP_EOL;
}
flock($lock, LOCK_UN);
```

Edit `public_html/.htaccess`: сразу после строки `RewriteRule ^api/stock_pull\.php$ - [R=404,L]` добавить

```apache

# Тик чат-менеджера (таймеры, автоудаление) — тоже только cron. Скрипт и сам
# отвечает 404 веб-запросу.
RewriteRule ^api/cm_cron\.php$ - [R=404,L]
```

- [ ] **Step 9: Run tests**

Run:
```bash
/c/xampp/php/php.exe tests/cm_router_test.php
/c/xampp/php/php.exe tests/telegram_test.php
/c/xampp/php/php.exe -l public_html/api/tg_webhook.php
/c/xampp/php/php.exe -l public_html/api/cm_cron.php
```
Expected: оба теста `0 failures`, `No syntax errors detected` дважды.

- [ ] **Step 10: Commit**

```bash
git add public_html/api/lib/cm/router.php public_html/api/lib/cm/cron.php public_html/api/lib/cm/cm.php public_html/api/cm_cron.php public_html/api/tg_webhook.php public_html/api/lib/telegram.php public_html/.htaccess tests/cm_router_test.php tests/telegram_test.php
git commit -m "feat(cm): маршрут вебхука в чат-менеджер, cron таймеров" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git show --stat HEAD
```

---

### Task 13: Описание, полный прогон, PR

**Files:**
- Create: `docs/chat-manager.md`
- Modify: `docs/telegram-notify.md` — ссылка на новый документ в разделе «Как это устроено»

- [ ] **Step 1: Описание**

Write `docs/chat-manager.md`:

````markdown
# Чат-менеджер (этап 1: модерация)

Наш бот модерирует любые группы, куда его добавят: ранги, наказания,
фильтры с триггерами, приветствие и правила, репорты, общая база спамеров,
чистка, заметки, таймеры. Только русский язык. Спека —
`docs/superpowers/specs/2026-11-07-chat-manager-moderation-design.md`.

Сам, без команды модератора, бот делает две вещи: удаляет ссылки (кроме
maknemy.com и доменов чата) и банит спамеров из общей базы. Всё остальное
включают модераторы командами (`помощь` в группе).

## Запуск на бою

1. Мерж PR → деплой.
2. phpMyAdmin: выполнить `docs/migrations/2026-11-07-chat-manager.sql`. Пока
   таблиц нет, модуль молчит.
3. BotFather → `/setprivacy` → бот → **Disable**. Telegram применяет это к
   группе, только когда бота удалят и добавят заново — в том числе в
   «Башне Шута».
4. `/admin/support` → «Подключить вебхук» (появятся правки сообщений, а у
   групп в правах по умолчанию — удаление сообщений).
5. cPanel → Cron Jobs, раз в минуту:
   `* * * * * /usr/local/bin/php /home/maknemyt/public_html/api/cm_cron.php >/dev/null 2>&1`
6. Проверка в тестовой группе: добавить бота админом, `помощь`, ссылка от
   второго аккаунта (удалится), `мут`, `+заметка`, `таймер через 2м`.

Выключить модуль целиком: `'cm_enabled' => false` в `/home/maknemyt/config.php`.

## База спамеров

Вносят только наши: `admin_ids` и `moderator_ids` из config, у которых
привязан Telegram (колокольчик на сайте). Команды `+спамер`, `-спамер`,
`проверить` работают в любой группе с ботом и в личке бота (там — по id).
`+спамер` банит человека во всех чатах, где бот его видел и где фильтр
«спамеры» включён.

## Как устроено

- `api/tg_webhook.php` — апдейт из группы и кнопки `cm:` уходят в
  `cm_handle_update()` после ответа 200; `my_chat_member` / `chat_member` —
  ещё и в `cm_on_membership()`.
- `api/lib/cm/` — по файлу на задачу: `parse` (команды, сроки, цель),
  `ranks`, `punish`, `filters` + `words`, `spam`, `welcome`, `reports`,
  `cleanup`, `notes`, `timers`, `router`, `cron`, `send` (бюджет 15 сообщений
  в минуту на группу), `queue` (автоудаление через минуту), `texts` (все тексты).
- `api/cm_cron.php` — раз в минуту: таймеры, автоудаление, чистка `cm_msgs`
  старше 48 часов.
- Ограничения Telegram: список участников боту не отдают — массовые кики
  только по тем, кого бот видел; сообщения старше 48 часов бот удалить не
  может; мут работает только в супергруппе.

## Дальше

Этап 2 — активность (статистика, профиль, репутация, награды), этап 3 —
игры и отношения. Строятся на тех же таблицах `cm_*` и модуле.
````

Edit `docs/telegram-notify.md`: в разделе «## Как это устроено», после пункта про `api/lib/telegram.php`, добавить пункт

```markdown
- Группы (модерация, фильтры, репорты) — чат-менеджер, `docs/chat-manager.md`.
```

- [ ] **Step 2: Полный прогон**

Run:
```bash
for t in tests/*_test.php tests/harness_selfcheck.php; do /c/xampp/php/php.exe -d extension=gd "$t" > /dev/null 2>&1 || echo "FAIL $t"; done; echo done
```
Expected: одна строка `done`, ни одной `FAIL`. В цикл входит `tests/php74_compat_test.php` — он проверяет все новые файлы в `public_html`. Если что-то упало — запустить этот тест отдельно и смотреть вывод. JS-тесты (`*_test.mjs`) эта работа не трогает.

- [ ] **Step 3: Проверка мусорных байтов**

Run:
```bash
git diff --stat maknemy/master...HEAD | grep -c Bin
git diff maknemy/master...HEAD -U0 | grep -cP '\x{00A0}|\x{200B}|\x{FEFF}'
```
Expected: `0` и `0`.

- [ ] **Step 4: Commit**

```bash
git add docs/chat-manager.md docs/telegram-notify.md
git commit -m "docs(cm): чат-менеджер — запуск и устройство" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 5: Push и PR**

`master` защищён: только ветка и PR, мержит владелец. Push в `maknemy` (живой remote; `origin` мёртв). Если `git push` висит ~21 с при живом `curl` — недоступен IP GitHub: `git -c http.sslbackend=schannel -c http.curloptResolve=github.com:443:<живой IP> push …`.

Write `<скратчпад>/pr-body.md` (вне репозитория):

```markdown
Наш бот теперь модерирует любые группы, куда его добавят, — как чат-менеджеры уровня «Ириса». Этап 1 из трёх: модерация. Дальше — активность, потом игры и отношения.

**Сам, без команды модератора, бот делает только две вещи:** удаляет ссылки (кроме maknemy.com и доменов, которые разрешит чат) и банит спамеров из общей базы. Остальное включают модераторы командами.

**Команды** (префикс `! . /` не обязателен, цель — ответом, @ником или id):
- ранги 0–5: `+модер 2`, `повысить`, `понизить`, `-модер`, `модеры`, `журнал рангов`, `передать создателя`, `вернуть создателя`
- доступ: `дк`, `дк бан 2`, `дк мут 6` (выключить)
- наказания: `варн`, `мут 1ч`, `кик`, `бан 7 дней`, `размут`, `разбан`, `варны`, `муты`, `баны`, `варны лимит 3`, `варны наказание бан 7 дней`
- фильтры: `фильтр мат вкл` (мат, ссылки, пересылки, флуд, капс, стикеры, гифки, голосовые), `триггер мат: удалить, варн`, `+слово`, `+домен`
- вход: `+приветствие`, `+правила`, `правила`
- жалобы: `репорт причина` ответом, кнопки у модераторов, отдельный чат админов через `репорты в чат`
- база спамеров: `+спамер`, `-спамер`, `проверить` — только наши `admin_ids` / `moderator_ids` с привязанным Telegram
- чистка: `чистка 50`, `чистка @ник`, `кик удалённых`, `кик неактивных 30 дней`, `кик новых 1ч` (с подтверждением)
- заметки `+заметка имя` / `#имя`, таймеры `таймер через 2ч` (до 5 на чат)

Устройство и запуск — `docs/chat-manager.md`, спека — `docs/superpowers/specs/2026-11-07-chat-manager-moderation-design.md`.

**После мержа:**
1. phpMyAdmin: `docs/migrations/2026-11-07-chat-manager.sql`.
2. BotFather → `/setprivacy` → бот → Disable, затем удалить бота из групп и добавить заново (в том числе «Башня Шута») — иначе Telegram не применит.
3. `/admin/support` → «Подключить вебхук».
4. cPanel → Cron Jobs: `* * * * * /usr/local/bin/php /home/maknemyt/public_html/api/cm_cron.php >/dev/null 2>&1`.
5. Тестовая группа: `помощь`, ссылка со второго аккаунта, `мут`, `+заметка`, `таймер через 2м`.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
```

```bash
git push -u maknemy feat/chat-manager-moderation
gh pr create --repo kannurali/MaknemyTierlist --base master --head feat/chat-manager-moderation --title "Чат-менеджер, этап 1: модерация групп" --body-file "<скратчпад>/pr-body.md"
```

`<скратчпад>` — папка скратчпада сессии исполнителя (абсолютный путь).
