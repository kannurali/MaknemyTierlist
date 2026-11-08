# Чат-менеджер, этап 2 (активность) — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** в любой группе с ботом — статистика сообщений и топы, анкета и профиль участника, репутация, награды. Всё по командам участников, бот сам ничего не отправляет; любой раздел модератор выключает через `дк <раздел> 6`.

**Architecture:** четыре новых файла модуля `public_html/api/lib/cm/` — `stats.php`, `rep.php`, `awards.php`, `profile.php` — на правилах этапа 1: функции с явными параметрами, контекст `$ctx` у обработчиков `cm_cmd_*`, тексты в `CM_TEXTS`, команды в `CM_COMMANDS`, разделы в `CM_SECTIONS`. Роутер после проверки спамера прибавляет сообщение в статистику (`cm_stats_hit`), а перед поиском команды ловит голос ответом (`cm_rep_try`). Таблицы — одной миграцией; пока её нет, команды этапа 2 отвечают «ещё не настроено» (`cm_ready2`), а модерация работает как раньше.

**Tech Stack:** PHP 7.4 (бой) / 8.0 (локально, XAMPP), MySQL на бою, SQLite в тестах, Telegram Bot API, собственный тестовый каркас `tests/lib.php`.

**Спека:** `docs/superpowers/specs/2026-11-08-chat-manager-activity-design.md`. Основа — этап 1: PR #222, код в `public_html/api/lib/cm/`, тесты `tests/cm_*`, план `docs/superpowers/plans/2026-11-07-chat-manager-moderation.md`. Код этапа 1 местами отличается от его плана — опирайтесь на код. Этап 3 (`docs/superpowers/specs/2026-11-08-chat-manager-games-design.md`) строится на функциях этого плана — их список в разделе «Что берёт этап 3».

## Global Constraints

- **PHP 7.4.** Нельзя: `str_contains`/`str_starts_with`/`str_ends_with`, `match`, `?->`, `#[...]`, union-типы (`int|string`), `mixed`, именованные аргументы, `catch` без переменной, `enum`, `readonly`. Можно: `fn() =>`, `??`, `??=`, типизированные параметры, `?int`. Проверка — `tests/php74_compat_test.php` (сканирует `public_html` рекурсивно).
- **Переносимый SQL.** Без `ON DUPLICATE KEY`, `ON CONFLICT`, `INSERT IGNORE`, `REPLACE`. Upsert: `UPDATE`, затем `INSERT` в `try/catch (PDOException $e)`. MySQL-ный `rowCount()` считает только изменённые строки. `LIMIT` — числом в строке запроса (`' LIMIT ' . (int)$n`), не параметром: эмуляция подготовленных запросов MySQL возьмёт параметр в кавычки. Сравнение с `SUM()` из подзапроса — тоже числом в строке: SQLite сравнивает выражение без типа со строковым параметром как текст.
- **Имена столбцов:** не `rank` (зарезервировано в MySQL 8), а `lvl`. «Нет срока», «не снято» — `0`, не `NULL`. `level` у наград (как в спеке) в MySQL не зарезервирован.
- **Ответы бота только на русском.** Все тексты — в `public_html/api/lib/cm/texts.php` (`CM_TEXTS`), из кода — через `cm_t()`.
- **Комментарии в PHP можно** (сервер их не отдаёт). В `public_html/js|css` и HTML — нельзя (здесь их не трогаем).
- **Новые PHP-файлы писать только инструментом Write**, не heredoc. Правки существующих — Edit; в `new_string` нельзя `\u…`, `\x…` и управляющих символов (Edit раскрывает их в настоящие байты), а `\n`, `\s`, `\d` в PHP-строках можно. После каждого коммита — `git show --stat HEAD`: строк `Bin` быть не должно.
- **PHP для тестов:** `/c/xampp/php/php.exe` (Git Bash, из корня worktree). Один тест — `/c/xampp/php/php.exe tests/cm_stats_test.php`. Набор — циклом с `-d extension=gd` (у XAMPP выключен GD), а не `tests/run_all.sh`: тот с `set -e` встанет на `images_test.php`.
- **Числа из SQLite в PHP 8.0 приходят строками** — в коде и тестах сравнивать через `(int)`.
- **В сообщениях тестов не писать `"«$var»"`:** байты «»» PHP читает как часть имени переменной и сыплет Warning. Писать `"«{$var}»"` или без кавычек-ёлочек.
- **Новые ключи настроек — только через `CM_DEFAULTS`** (`db.php`), иначе `cm_settings()` их отбросит. В этом этапе новых ключей нет. Обработчик, который сохраняет настройки и дальше их читает, держит `$ctx` по ссылке (`cm_save()` в `filters.php`).
- **Даты по Москве** (`CM_TZ`). День статистики — число `ГГГГММДД` (`cm_day()`). Смену суток тесты проверяют на `CM_T2359` / `CM_T0001` (21.09.2026 23:59 и 22.09.2026 00:01 МСК, Task 1).
- **Бот сам ничего не отправляет.** Счётчик сообщений молчит; всё остальное — ответ на команду или голос. Все ответы — через `cm_reply` / `cm_say` (бюджет этапа 1: 15 сообщений в минуту на группу). Временные (`cm_reply($ctx, $text)`, удаляются через минуту): голоса, отказы, подсказки, `репа`, «сохранено» анкеты. Постоянные (`cm_reply($ctx, $text, false)`): топы, профиль, `моя стата`, список наград, выдача и снятие награды.
- **Ветка:** `feat/chat-manager-activity` (отрезана от `feat/chat-manager-moderation`, в ней уже спеки этапов 2–3 и этот план). В `master` не пушить; в конце — push ветки и PR (Task 6).
- **Коммиты** заканчиваются строкой `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Решения поверх спеки

Спека их не запрещает, но и не называет; ревьюеру — чтобы не принять за отступление.

1. **Команды без префикса с необязательной целью и аргументами.** Пять полей `CM_COMMANDS` этапа 1 не умеют сказать «`моя стата` — ровно команда, но можно `@ник`» и «`стата 15 месяц` — с аргументами, а `стата норм` — речь». Новая константа `CM_BARE_ARGS` (`parse.php`): обработчик → шаблон остатка строки без цели. `cm_run_command` первой строкой зовёт `cm_bare_ok()`; с префиксом проверки нет. Формат `CM_COMMANDS` не меняется, поведение команд этапа 1 — тоже. Этап 3 добавит рядом `CM_PREFIX_ONLY`.
2. **Что видно всегда.** `профиль`, `кто я`, `кто ты`, `награды` — вне разделов (`null`), как `правила`, `заметки`, `фильтры` в этапе 1. Иначе `дк награды 2` (дать выдавать награды с ранга 2) заодно спрятал бы список наград от участников. Раздел `награды` — это выдача и снятие (ранг 3 по умолчанию). `дк <раздел> 6` прячет строки раздела из профиля; награды в профиле видны всегда.
3. **`кто я`** — свой профиль без цели (`exact`, как было). Без таблиц этапа 2 отвечает текстом этапа 1.
4. **Переезд группы в супергруппу** (`cm_migrate`) уносит и таблицы этапа 2, если его миграция выполнена.
5. **«Сутки» в ограничениях репутации** — скользящие 24 часа (и для пары, и для 20 голосов).
6. **Голоса:** служебный аккаунт Telegram `777000` (автопересылка из канала) — как бот. В `топ репы` нет покинувших чат, как в топе статистики.
7. **Награды:** себе нельзя; срок — только после «на» (иначе причина «100 побед» стала бы сроком в 100 минут).
8. **Анкета:** город и «о себе» без ссылок (`cm_has_link` с пустым списком доменов — разрешён только maknemy.com) и без встроенного мата. Анкету показывают во всех чатах, а сообщение-команду фильтры уже не проверяют.
9. **Счётчик** не тратит запрос на `cm_ready2`: ловит `PDOException`, если таблиц нет. Он стоит до фильтров (как в спеке), поэтому удалённое фильтром сообщение тоже считается.

## Карта файлов

| Файл | Что | Задача |
| --- | --- | --- |
| `docs/migrations/2026-11-08-chat-manager-activity.sql` | таблицы `cm_stats`, `cm_profiles`, `cm_rep`, `cm_rep_log`, `cm_awards` | 1 |
| `tests/cm_schema.php` | те же таблицы для SQLite | 1 |
| `tests/cm_helpers.php` | `CM_T2359`, `CM_T0001` | 1 |
| `public_html/api/lib/cm/stats.php` | `cm_ready2`, дни по Москве, счётчик, топы, `стата`, `моя стата` | 1 |
| `public_html/api/lib/cm/rep.php` | голоса, `+реп`/`-реп`, `репа`, `топ репы` | 2 |
| `public_html/api/lib/cm/awards.php` | `+награда`, `-награда`, `награды` | 3 |
| `public_html/api/lib/cm/profile.php` | анкета, `cm_profile_lines`, `профиль`, `кто ты` | 4 |
| `public_html/api/lib/cm/parse.php` | строки `CM_COMMANDS`, `CM_BARE_ARGS`, `cm_bare_ok` | 1–4 |
| `public_html/api/lib/cm/texts.php` | тексты, `помощь` | 1–5 |
| `public_html/api/lib/cm/ranks.php` | `CM_SECTIONS`: `статистика`, `репутация`, `награды`, `анкета` | 1–4 |
| `public_html/api/lib/cm/db.php` | `cm_migrate` с таблицами этапа 2 | 1 |
| `public_html/api/lib/cm/router.php` | `cm_bare_ok` в `cm_run_command`; `кто я`; счётчик и голос в `cm_on_message` | 1, 4, 5 |
| `public_html/api/lib/cm/cm.php` | части `stats`, `rep`, `awards`, `profile` после `timers`, до `router` | 1–4 |
| `docs/chat-manager.md` | запуск и устройство этапа 2 | 6 |

Тесты: `tests/cm_stats_test.php` (1), `cm_rep_test.php` (2), `cm_awards_test.php` (3), `cm_profile_test.php` (4), правки `tests/cm_router_test.php` (4, 5). Все подхватываются по маске `*_test.php`.

Каждый новый тест-файл начинается так же, как в этапе 1:

```php
<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';
```

Помощники этапа 1 (`tests/cm_helpers.php`), которыми пользуются тесты ниже: `cm_test_pdo()` (SQLite в памяти со всеми таблицами `cm_*`), `cm_ctx_for($pdo, &$log, $actor, $text = '', $extra = [], $answers = [], $now = CM_NOW)` (контекст обработчика; `$extra` дописывается в сообщение — например `reply_to_message`), `cm_do($ctx, $text)` (вызвать обработчик команды напрямую, без проверки ранга; не команда — исключение «не команда: …»), `cm_rec(&$log)`, `cm_calls($log, $method)`, `cm_texts($log)`, `cm_last_text($log)`, `cm_gmsg($from, $text, $extra = [])`, `cm_person($pdo, $id, $username, $name = '')` (имя по умолчанию `U<id>`), `cm_lvl($pdo, $userId, $lvl)`. Константы: `CM_NOW` = 1790000000 (21.09.2026 17:13 МСК), `CM_CHAT`, `CM_BOT`, `CM_OWNER` (1, создатель группы, ранг 5), `CM_ADMIN` (2). Подставной `$send` на `sendMessage` отвечает `message_id` = 5000 + номер вызова.

## Что берёт этап 3

Этап 3 планируют по этому плану. Его опоры (все — в задачах ниже, с теми же именами):

- `cm_ready2(PDO $pdo): bool` и `cm_need2(array $ctx): bool` — проверка таблиц этапа 2 (Task 1). Этап 3 заводит свои `cm_ready3` / `cm_need3` по тому же образцу.
- `cm_stats_msgs(PDO $pdo, int $chatId, int $userId, int $days, int $now): int` — сообщения участника за `$days` дней, сегодня включительно, `0` — за всё время (Task 1). Для рейтинга кланов одним запросом — `cm_day_from(int $now, int $days): int` (первый день периода, `ГГГГММДД`) и своё `JOIN cm_stats … AND day >= :d`.
- `cm_stats_top(PDO $pdo, int $chatId, int $days, int $now, int $limit): array` — кто писал за период, без покинувших (для `!кто`; ботов в `cm_stats` нет: роутер ботов не считает).
- `cm_day(int $ts): int` — день по Москве (для «инфа» и опыта пары за сутки).
- `cm_profile_lines(array $ctx, int $userId, string $name): array` — строки профиля (Task 4). Этап 3 вставляет «Пара», «Брак», «Клан», «Дуэли» после строки «Награды», перед «Варны».
- `cm_profile_get(PDO $pdo, int $userId): array` — `['gender' => ''|'m'|'f', 'city', 'birthday', 'about']` (форма глагола в РП).
- `cm_section_on(array $chat, string $section): bool` — включён ли раздел `дк` (Task 4).
- `cm_target_or_self(array $ctx, string &$args): ?array` — цель или сам автор (Task 1).
- `CM_BARE_ARGS` и `cm_bare_ok()` в `parse.php`, первая строка `cm_run_command` (Task 1) — рядом встанет `CM_PREFIX_ONLY`.
- `cm_num(int $n): string` — «1 234».

## Порядок исполнения

Задачи идут строго по порядку: каждая следующая дописывает те же строки `parse.php`, `texts.php`, `ranks.php`, `cm.php`, что и предыдущая. Разумное деление на две сессии:

- **Сессия 1 — Task 1–2:** таблицы и статистика (самая большая задача: миграция, `CM_BARE_ARGS`), репутация.
- **Сессия 2 — Task 3–6:** награды, анкета и профиль, роутер, описание и PR.

В конце сессии — записка в общей папке сессий: коммиты, счёт тестов, отступления от плана.

---

### Task 1: Таблицы этапа 2 и статистика

**Files:**
- Create: `docs/migrations/2026-11-08-chat-manager-activity.sql`
- Create: `public_html/api/lib/cm/stats.php`
- Modify: `tests/cm_schema.php`, `tests/cm_helpers.php`
- Modify: `public_html/api/lib/cm/db.php` (`cm_migrate`), `parse.php` (команды, `CM_BARE_ARGS`), `router.php` (`cm_run_command`), `ranks.php` (`CM_SECTIONS`), `texts.php`, `cm.php`
- Test: `tests/cm_stats_test.php`

**Interfaces:**
- Consumes (этап 1): `cm_target(PDO $pdo, array $msg, string &$args, ?string &$why = null): ?array` (цель `['id' => int, 'name' => string, 'is_bot' => bool, 'msg_id' => int]`, `$why = 'unknown'` — незнакомый ник), `cm_reply(array $ctx, string $text, bool $temp = true, array $o = []): int`, `cm_t(string $key, array $vars = []): string`, `cm_norm(string $s): string`, `cm_member(PDO $pdo, int $chatId, int $userId): ?array`, `cm_display_name(array $u): string`, `cm_user_name(PDO $pdo, int $id): string`, `cm_chat_row`, `cm_run_command(array $ctx, array $cmd): bool`, `CM_TZ`. `$ctx` — из `cm_ctx()`: `pdo, cfg, send, chat, msg, now, actor, rank, edited, timer, bot, thread_id`. `$cmd` — из `cm_parse()`: `phrase, handler, section, level, exact, target, prefixed, args, body`.
- Produces:
  - `cm_ready2(PDO $pdo): bool`, `cm_need2(array $ctx): bool` (нет таблиц — ответ `not_ready2` и `false`)
  - `cm_day(int $ts): int`, `cm_day_from(int $now, int $days): int` (`0` при `$days <= 0`), `cm_day_text(int $day): string` (`20261107` → «07.11.2026»), `cm_num(int $n): string`
  - `cm_target_or_self(array $ctx, string &$args): ?array` — цель, без неё сам автор (`name` — `cm_display_name` отправителя); незнакомый `@ник` — ответ `target_unknown` и `null`
  - `cm_stats_hit(PDO $pdo, int $chatId, int $userId, int $now): void`
  - `cm_stats_msgs(PDO $pdo, int $chatId, int $userId, int $days, int $now): int`, `cm_stats_total(PDO $pdo, int $chatId, int $days, int $now): int`
  - `cm_stats_top(PDO $pdo, int $chatId, int $days, int $now, int $limit): array` — `[['user_id' => int, 'msgs' => int], …]`
  - `cm_stats_place(PDO $pdo, int $chatId, int $userId, int $days, int $now): int`, `cm_stats_first_day(PDO $pdo, int $chatId, int $userId): int`
  - `cm_stats_args(string $args): ?array` — `[мест, дней]`; обработчики `cm_cmd_stats_top`, `cm_cmd_stats_me`
  - `CM_BARE_ARGS` (обработчик → шаблон), `cm_bare_ok(array $ctx, array $cmd): bool`; `CM_CHAT_TABLES2_PK`, `CM_CHAT_TABLES2_ROWS`
  - Тексты: `not_ready2`, `stats_usage`, `stats_head`, `stats_line`, `stats_empty`, `stats_p_1|7|30|0`, `stats_me`, `stats_place`
  - Тесты: `CM_T2359` = 1790024340, `CM_T0001` = 1790024460

- [ ] **Step 1: Миграция**

Write `docs/migrations/2026-11-08-chat-manager-activity.sql`:

```sql
-- Чат-менеджер, этап 2 (активность): статистика, анкета, репутация, награды.
-- Код — api/lib/cm/stats.php, profile.php, rep.php, awards.php; описание —
-- docs/chat-manager.md. Запускать один раз, ПОСЛЕ 2026-11-07-chat-manager.sql.
-- Пока этих таблиц нет, команды этапа 2 отвечают «ещё не настроено»
-- (cm_ready2 в api/lib/cm/stats.php), а модерация работает как раньше.
--
-- «Нет срока» и «не снято» — 0, а не NULL. LEVEL в MySQL не зарезервирован.

-- Сообщения за день. day — дата по Москве числом ГГГГММДД.
CREATE TABLE IF NOT EXISTS cm_stats (
  chat_id BIGINT       NOT NULL,
  user_id BIGINT       NOT NULL,
  day     INT UNSIGNED NOT NULL,
  msgs    INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (chat_id, user_id, day),
  KEY idx_day (chat_id, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Анкета — одна на человека для всех чатов. gender: '' / 'm' / 'f';
-- birthday: «ДД.ММ» или «ДД.ММ.ГГГГ».
CREATE TABLE IF NOT EXISTS cm_profiles (
  user_id    BIGINT          NOT NULL PRIMARY KEY,
  gender     CHAR(1)         NOT NULL DEFAULT '',
  city       VARCHAR(64)     NOT NULL DEFAULT '',
  birthday   VARCHAR(10)     NOT NULL DEFAULT '',
  about      VARCHAR(200)    NOT NULL DEFAULT '',
  updated_at BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Репутация в чате.
CREATE TABLE IF NOT EXISTS cm_rep (
  chat_id BIGINT NOT NULL,
  user_id BIGINT NOT NULL,
  score   INT    NOT NULL DEFAULT 0,
  PRIMARY KEY (chat_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Кто кому и когда голосовал — для ограничений «раз в 24 часа на пару» и
-- «20 голосов за 24 часа».
CREATE TABLE IF NOT EXISTS cm_rep_log (
  id      INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
  chat_id BIGINT          NOT NULL,
  from_id BIGINT          NOT NULL,
  to_id   BIGINT          NOT NULL,
  delta   TINYINT         NOT NULL,
  at      BIGINT UNSIGNED NOT NULL,
  KEY idx_from (chat_id, from_id, at),
  KEY idx_pair (chat_id, from_id, to_id, at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Награды: level 1–8, until_at 0 — навсегда, removed_at 0 — действует.
CREATE TABLE IF NOT EXISTS cm_awards (
  id         INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
  chat_id    BIGINT          NOT NULL,
  user_id    BIGINT          NOT NULL,
  level      TINYINT         NOT NULL,
  reason     VARCHAR(100)    NOT NULL,
  by_id      BIGINT          NOT NULL DEFAULT 0,
  created_at BIGINT UNSIGNED NOT NULL,
  until_at   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  removed_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
  KEY idx_user (chat_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- [ ] **Step 2: Таблицы для SQLite и метки смены суток**

Edit `tests/cm_schema.php` — перед строкой

```php
    $pdo->exec("CREATE TABLE IF NOT EXISTS tg_links (
```

вставить (сама строка `tg_links` остаётся следом):

```php
    // этап 2 — docs/migrations/2026-11-08-chat-manager-activity.sql
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_stats (
        chat_id INTEGER NOT NULL, user_id INTEGER NOT NULL, day INTEGER NOT NULL,
        msgs INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (chat_id, user_id, day))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_profiles (
        user_id INTEGER PRIMARY KEY, gender TEXT NOT NULL DEFAULT '', city TEXT NOT NULL DEFAULT '',
        birthday TEXT NOT NULL DEFAULT '', about TEXT NOT NULL DEFAULT '', updated_at INTEGER NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_rep (
        chat_id INTEGER NOT NULL, user_id INTEGER NOT NULL, score INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (chat_id, user_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_rep_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT, chat_id INTEGER NOT NULL, from_id INTEGER NOT NULL,
        to_id INTEGER NOT NULL, delta INTEGER NOT NULL, at INTEGER NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_awards (
        id INTEGER PRIMARY KEY AUTOINCREMENT, chat_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
        level INTEGER NOT NULL, reason TEXT NOT NULL, by_id INTEGER NOT NULL DEFAULT 0,
        created_at INTEGER NOT NULL, until_at INTEGER NOT NULL DEFAULT 0, removed_at INTEGER NOT NULL DEFAULT 0)");
```

Edit `tests/cm_helpers.php`: строку

```php
const CM_ADMIN = 2;   // админ Telegram без ранга бота
```

заменить на

```php
const CM_ADMIN = 2;   // админ Telegram без ранга бота
// Смена суток по Москве (этап 2): 21.09.2026 23:59 и 22.09.2026 00:01 МСК.
const CM_T2359 = 1790024340;
const CM_T0001 = 1790024460;
```

- [ ] **Step 3: Падающий тест**

Write `tests/cm_stats_test.php`:

```php
<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';

// Статистика (этап 2): api/lib/cm/stats.php, команды без префикса
// (CM_BARE_ARGS в parse.php), переезд группы вместе с таблицами этапа 2.

function st_put(PDO $pdo, int $userId, int $day, int $msgs, int $chatId = CM_CHAT): void {
    $pdo->prepare('INSERT INTO cm_stats (chat_id, user_id, day, msgs) VALUES (:c, :u, :d, :m)')
        ->execute([':c' => $chatId, ':u' => $userId, ':d' => $day, ':m' => $msgs]);
}

test('таблицы этапа 2: есть — готово, нет — нет, этап 1 при этом жив', function () {
    assert_eq(true, cm_ready2(cm_test_pdo()));
    $pdo = cm_test_pdo();
    $pdo->exec('DROP TABLE cm_stats');
    assert_eq(false, cm_ready2($pdo));
    assert_eq(true, cm_ready($pdo));
    cm_stats_hit($pdo, CM_CHAT, 7, CM_NOW);
    assert_eq(false, cm_ready2($pdo), 'счётчик без таблиц молчит, без исключения');
});

test('сутки по Москве: 23:59 и 00:01 — разные дни', function () {
    $msk = function (int $ts): string {
        $d = new DateTime('@' . $ts);
        $d->setTimezone(new DateTimeZone('Europe/Moscow'));
        return $d->format('Y-m-d H:i');
    };
    assert_eq('2026-09-21 23:59', $msk(CM_T2359));
    assert_eq('2026-09-22 00:01', $msk(CM_T0001));
    assert_eq(20260921, cm_day(CM_T2359));
    assert_eq(20260922, cm_day(CM_T0001));
    $pdo = cm_test_pdo();
    cm_stats_hit($pdo, CM_CHAT, 7, CM_T2359);
    cm_stats_hit($pdo, CM_CHAT, 7, CM_T2359);
    assert_eq(2, cm_stats_msgs($pdo, CM_CHAT, 7, 1, CM_T2359), 'сегодня до полуночи');
    cm_stats_hit($pdo, CM_CHAT, 7, CM_T0001);
    assert_eq(1, cm_stats_msgs($pdo, CM_CHAT, 7, 1, CM_T0001), 'сегодня после полуночи');
    $rows = $pdo->query('SELECT day, msgs FROM cm_stats ORDER BY day')->fetchAll(PDO::FETCH_NUM);
    assert_eq([[20260921, 2], [20260922, 1]], array_map(function ($r) { return [(int)$r[0], (int)$r[1]]; }, $rows));
    assert_eq(3, cm_stats_msgs($pdo, CM_CHAT, 7, 0, CM_T0001));
});

test('неделя и месяц — сегодня включительно, границы', function () {
    assert_eq(20260922, cm_day_from(CM_T0001, 1));
    assert_eq(20260916, cm_day_from(CM_T0001, 7));
    assert_eq(20260824, cm_day_from(CM_T0001, 30));
    assert_eq(20260915, cm_day_from(CM_T2359, 7));
    assert_eq(0, cm_day_from(CM_T0001, 0));
    $pdo = cm_test_pdo();
    st_put($pdo, 7, 20260922, 1);
    st_put($pdo, 7, 20260916, 10);     // 7-й день — ещё неделя
    st_put($pdo, 7, 20260915, 100);    // 8-й — уже нет
    st_put($pdo, 7, 20260824, 1000);   // 30-й — ещё месяц
    st_put($pdo, 7, 20260823, 10000);  // 31-й — только «всё»
    assert_eq(11, cm_stats_msgs($pdo, CM_CHAT, 7, 7, CM_T0001));
    assert_eq(1111, cm_stats_msgs($pdo, CM_CHAT, 7, 30, CM_T0001));
    assert_eq(11111, cm_stats_msgs($pdo, CM_CHAT, 7, 0, CM_T0001));
    assert_eq(20260823, cm_stats_first_day($pdo, CM_CHAT, 7));
    assert_eq(0, cm_stats_first_day($pdo, CM_CHAT, 8));
    assert_eq('23.08.2026', cm_day_text(20260823));
    assert_eq('1 234 567', cm_num(1234567));
});

test('топ: ушедших нет, при равенстве — меньший id; место', function () {
    $pdo = cm_test_pdo();
    st_put($pdo, 7, 20260922, 5);
    st_put($pdo, 8, 20260922, 9);
    st_put($pdo, 9, 20260922, 5);
    st_put($pdo, 10, 20260922, 50);
    cm_member_set($pdo, CM_CHAT, 10, ['left_at' => CM_NOW]);
    assert_eq([['user_id' => 8, 'msgs' => 9], ['user_id' => 7, 'msgs' => 5], ['user_id' => 9, 'msgs' => 5]],
        cm_stats_top($pdo, CM_CHAT, 1, CM_T0001, 10));
    assert_eq(2, count(cm_stats_top($pdo, CM_CHAT, 1, CM_T0001, 2)));
    assert_eq(69, cm_stats_total($pdo, CM_CHAT, 1, CM_T0001), 'всего — вместе с ушедшими');
    assert_eq(1, cm_stats_place($pdo, CM_CHAT, 8, 0, CM_T0001));
    assert_eq(3, cm_stats_place($pdo, CM_CHAT, 9, 0, CM_T0001));
    assert_eq(0, cm_stats_place($pdo, CM_CHAT, 10, 0, CM_T0001), 'ушёл — без места');
    assert_eq(0, cm_stats_place($pdo, CM_CHAT, 11, 0, CM_T0001), 'не писал — без места');
    assert_eq([], cm_stats_top($pdo, -999, 0, CM_T0001, 10), 'чужой чат');
});

test('переезд группы уносит статистику, репутацию и награды', function () {
    $pdo = cm_test_pdo();
    cm_chat_ensure($pdo, ['id' => -500, 'title' => 'Старая'], CM_NOW);
    st_put($pdo, 7, 20260921, 3, -500);
    st_put($pdo, 7, 20260921, 1);   // успело появиться у нового id — уступает старому
    $pdo->exec('INSERT INTO cm_rep (chat_id, user_id, score) VALUES (-500, 7, 4)');
    $pdo->exec('INSERT INTO cm_rep_log (chat_id, from_id, to_id, delta, at) VALUES (-500, 8, 7, 1, 0)');
    $pdo->exec("INSERT INTO cm_awards (chat_id, user_id, level, reason, by_id, created_at) VALUES (-500, 7, 5, 'за дело', 1, 0)");
    cm_migrate($pdo, -500, CM_CHAT);
    assert_eq(3, cm_stats_msgs($pdo, CM_CHAT, 7, 0, CM_T0001));
    assert_eq(0, (int)$pdo->query('SELECT COUNT(*) FROM cm_stats WHERE chat_id = -500')->fetchColumn());
    foreach (['cm_rep', 'cm_rep_log', 'cm_awards'] as $t) {
        assert_eq(1, (int)$pdo->query("SELECT COUNT(*) FROM $t WHERE chat_id = " . CM_CHAT)->fetchColumn(), $t);
    }
});

test('переезд без таблиц этапа 2 — как раньше', function () {
    $pdo = cm_test_pdo();
    foreach (['cm_stats', 'cm_rep', 'cm_rep_log', 'cm_awards'] as $t) { $pdo->exec("DROP TABLE $t"); }
    cm_chat_ensure($pdo, ['id' => -500, 'title' => 'Старая'], CM_NOW);
    cm_member_set($pdo, -500, 7, ['lvl' => 2]);
    cm_migrate($pdo, -500, CM_CHAT);
    assert_eq(2, cm_member($pdo, CM_CHAT, 7)['lvl']);
});

test('стата: разбор числа и периода', function () {
    assert_eq([10, 1], cm_stats_args(''));
    assert_eq([20, 1], cm_stats_args('20'));
    assert_eq([10, 7], cm_stats_args('неделя'));
    assert_eq([15, 30], cm_stats_args('15 месяц'));
    assert_eq([15, 30], cm_stats_args('месяц 15'));
    assert_eq([10, 0], cm_stats_args('всё'));
    assert_eq([10, 0], cm_stats_args('ВСЕ'));
    assert_eq(null, cm_stats_args('0'));
    assert_eq(null, cm_stats_args('51'));
    assert_eq(null, cm_stats_args('норм'));
    assert_eq(null, cm_stats_args('за неделю'));
});

test('стата: топ за период, общее число, пусто', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 7, 'vasya', 'Вася');
    cm_person($pdo, 8, 'petya', 'Петя');
    st_put($pdo, 7, 20260922, 1200);
    st_put($pdo, 8, 20260916, 34);
    st_put($pdo, 8, 20260915, 1000);   // за пределами недели
    $ctx = cm_ctx_for($pdo, $log, 30, '', [], [], CM_T0001);
    cm_do($ctx, 'стата неделя');
    assert_eq("📊 Активность за неделю (всего 1 234):\n1. Вася — 1 200\n2. Петя — 34", cm_last_text($log));
    cm_do($ctx, 'стата 1');
    assert_eq("📊 Активность за сегодня (всего 1 200):\n1. Вася — 1 200", cm_last_text($log));
    cm_do($ctx, 'стата всё');
    assert_eq("📊 Активность за всё время (всего 2 234):\n1. Вася — 1 200\n2. Петя — 1 034", cm_last_text($log));
    cm_do($ctx, '!стата норм');
    assert_eq(cm_t('stats_usage'), cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, 30, '', [], [], CM_T0001 + 86400), 'стата');
    assert_eq(cm_t('stats_empty', ['period' => 'сегодня']), cm_last_text($log));
});

test('моя стата: своя, чужая ответом, незнакомый ник', function () {
    $pdo = cm_test_pdo();
    $log = [];
    st_put($pdo, 7, 20260922, 15);
    st_put($pdo, 7, 20260916, 5);
    st_put($pdo, 7, 20260901, 80);
    st_put($pdo, 7, 20260101, 900);
    st_put($pdo, 8, 20260922, 2000);
    $ctx = cm_ctx_for($pdo, $log, 7, '', [], [], CM_T0001);
    cm_do($ctx, 'моя стата');
    assert_eq("📊 U7\nСегодня: 15 · неделя: 20 · месяц: 100 · всего: 1 000\nМесто в топе за всё время: 2", cm_last_text($log));
    $reply = ['reply_to_message' => ['message_id' => 70, 'from' => ['id' => 8, 'first_name' => 'Петя']]];
    cm_do(cm_ctx_for($pdo, $log, 7, '', $reply, [], CM_T0001), 'моя стата');
    assert_eq(0, strpos(cm_last_text($log), "📊 Петя\nСегодня: 2 000"));
    cm_do($ctx, '!моя стата @nobody1');
    assert_eq(cm_t('target_unknown'), cm_last_text($log));
});

test('без префикса: «стата неделя» и «моя стата» ответом — команды, «стата норм» — речь', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $run = function (string $text, array $extra = []) use ($pdo, &$log): bool {
        $ctx = cm_ctx_for($pdo, $log, 7, $text, $extra, [], CM_T0001);
        return cm_run_command($ctx, cm_parse($text, CM_BOT));
    };
    $reply = ['reply_to_message' => ['message_id' => 70, 'from' => ['id' => 8, 'first_name' => 'Петя']]];
    assert_eq(true, $run('стата неделя'));
    assert_eq(true, $run('стата 15 месяц'));
    assert_eq(true, $run('моя стата', $reply));
    $n = count(cm_calls($log, 'sendMessage'));
    assert_eq(false, $run('стата норм'));
    assert_eq(false, $run('моя стата лучше твоей'));
    assert_eq(false, $run("стата\nи ещё строка"));
    assert_eq($n, count(cm_calls($log, 'sendMessage')), 'обычная речь — без ответа');
    assert_eq(true, $run('!стата норм'), 'с префиксом — команда, в ответ подсказка');
});

test('дк статистика 6 выключает стату', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), 'дк статистика 6');
    assert_eq(cm_t('access_done', ['section' => 'статистика', 'level' => 6]), cm_last_text($log));
    $ctx = cm_ctx_for($pdo, $log, 7, '!стата');
    assert_eq(true, cm_run_command($ctx, cm_parse('!стата', CM_BOT)));
    assert_eq(cm_t('cmd_off'), cm_last_text($log));
});

test('нет таблиц этапа 2 — «ещё не настроено»', function () {
    $pdo = cm_test_pdo();
    $pdo->exec('DROP TABLE cm_stats');
    $log = [];
    cm_do(cm_ctx_for($pdo, $log, 7), 'стата');
    assert_eq(cm_t('not_ready2'), cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, 7), 'моя стата');
    assert_eq(cm_t('not_ready2'), cm_last_text($log));
});

run_tests();
```

- [ ] **Step 4: Убедиться, что тест падает**

Run: `/c/xampp/php/php.exe tests/cm_stats_test.php`
Expected: FAIL — первая строка `ERROR - таблицы этапа 2: …: Call to undefined function cm_ready2()`.

- [ ] **Step 5: Статистика**

Write `public_html/api/lib/cm/stats.php`:

```php
<?php
// Статистика сообщений (этап 2): счётчик за день по Москве, топы, «моя стата».
// Считается с момента, когда бот появился в чате: прошлую переписку Telegram
// боту не отдаёт. Таблицы — docs/migrations/2026-11-08-chat-manager-activity.sql.

// Слово периода (после cm_norm) → дней; 0 — за всё время.
const CM_STATS_PERIODS = ['день' => 1, 'неделя' => 7, 'месяц' => 30, 'все' => 0];
const CM_STATS_TOP_MAX = 50;

// Суммы сообщений по людям, кто не покинул чат. Параметры :c и :d.
const CM_STATS_SUMS = 'SELECT s.user_id AS user_id, SUM(s.msgs) AS n FROM cm_stats s
    LEFT JOIN cm_members m ON m.chat_id = s.chat_id AND m.user_id = s.user_id
    WHERE s.chat_id = :c AND s.day >= :d AND (m.left_at IS NULL OR m.left_at = 0)
    GROUP BY s.user_id';

// Миграция этапа 2 выполняется руками после этапа 1. Пока её нет, команды
// этапа 2 отвечают «ещё не настроено», счётчик молчит, модерация работает.
function cm_ready2(PDO $pdo): bool {
    try {
        $pdo->query('SELECT 1 FROM cm_stats LIMIT 1');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

// Первая строка каждого обработчика этапа 2: нет таблиц — ответить и выйти.
function cm_need2(array $ctx): bool {
    if (cm_ready2($ctx['pdo'])) { return true; }
    cm_reply($ctx, cm_t('not_ready2'));
    return false;
}

// Дата по Москве числом ГГГГММДД.
function cm_day(int $ts): int {
    $d = new DateTime('@' . $ts);
    $d->setTimezone(new DateTimeZone(CM_TZ));
    return (int)$d->format('Ymd');
}

// Первый день периода из $days дней, сегодня включительно; 0 — с начала.
function cm_day_from(int $now, int $days): int {
    if ($days <= 0) { return 0; }
    $d = new DateTime('@' . $now);
    $d->setTimezone(new DateTimeZone(CM_TZ));
    $d->modify('-' . ($days - 1) . ' days');
    return (int)$d->format('Ymd');
}

// 20261107 → «07.11.2026».
function cm_day_text(int $day): string {
    return sprintf('%02d.%02d.%04d', $day % 100, intdiv($day, 100) % 100, intdiv($day, 10000));
}

// 1234567 → «1 234 567».
function cm_num(int $n): string {
    return number_format($n, 0, '', ' ');
}

// Цель команды, а без неё — сам автор. Незнакомый @ник — подсказка и null.
function cm_target_or_self(array $ctx, string &$args): ?array {
    $why = null;
    $t = cm_target($ctx['pdo'], $ctx['msg'], $args, $why);
    if ($t !== null) { return $t; }
    if ($why === 'unknown') {
        cm_reply($ctx, cm_t('target_unknown'));
        return null;
    }
    $from = is_array($ctx['msg']['from'] ?? null) ? $ctx['msg']['from'] : [];
    return ['id' => (int)$ctx['actor'], 'name' => cm_display_name($from), 'is_bot' => false, 'msg_id' => 0];
}

// --------------------------------------------------------------------------
//  Счётчик и выборки
// --------------------------------------------------------------------------

// +1 сообщение автору за сегодня. Нет таблиц этапа 2 — молча мимо.
function cm_stats_hit(PDO $pdo, int $chatId, int $userId, int $now): void {
    $p = [':c' => $chatId, ':u' => $userId, ':d' => cm_day($now)];
    try {
        $up = $pdo->prepare('UPDATE cm_stats SET msgs = msgs + 1 WHERE chat_id = :c AND user_id = :u AND day = :d');
        $up->execute($p);
        if ($up->rowCount() > 0) { return; }
        try {
            $pdo->prepare('INSERT INTO cm_stats (chat_id, user_id, day, msgs) VALUES (:c, :u, :d, 1)')->execute($p);
        } catch (PDOException $e) {
            $up->execute($p);   // параллельное сообщение уже завело строку
        }
    } catch (PDOException $e) {
        // таблиц этапа 2 ещё нет
    }
}

// Сообщения человека за $days дней (0 — за всё время). Им же этап 3 считает
// активность кланов.
function cm_stats_msgs(PDO $pdo, int $chatId, int $userId, int $days, int $now): int {
    $st = $pdo->prepare('SELECT COALESCE(SUM(msgs), 0) FROM cm_stats WHERE chat_id = :c AND user_id = :u AND day >= :d');
    $st->execute([':c' => $chatId, ':u' => $userId, ':d' => cm_day_from($now, $days)]);
    return (int)$st->fetchColumn();
}

// Всего сообщений в чате за период — вместе с ушедшими.
function cm_stats_total(PDO $pdo, int $chatId, int $days, int $now): int {
    $st = $pdo->prepare('SELECT COALESCE(SUM(msgs), 0) FROM cm_stats WHERE chat_id = :c AND day >= :d');
    $st->execute([':c' => $chatId, ':d' => cm_day_from($now, $days)]);
    return (int)$st->fetchColumn();
}

// Топ: [['user_id' => int, 'msgs' => int], …], больше — выше, при равенстве
// меньший id. Ушедших (left_at > 0) нет. LIMIT — числом в строке: эмуляция
// подготовленных запросов MySQL взяла бы параметр в кавычки.
function cm_stats_top(PDO $pdo, int $chatId, int $days, int $now, int $limit): array {
    $st = $pdo->prepare(CM_STATS_SUMS . ' ORDER BY n DESC, s.user_id LIMIT ' . max(1, $limit));
    $st->execute([':c' => $chatId, ':d' => cm_day_from($now, $days)]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['user_id' => (int)$r['user_id'], 'msgs' => (int)$r['n']];
    }
    return $out;
}

// Место в том же топе; 0 — сообщений нет или человек ушёл. Числа — в строке:
// SQLite сравнивает SUM() с параметром-строкой как текст.
function cm_stats_place(PDO $pdo, int $chatId, int $userId, int $days, int $now): int {
    $mine = cm_stats_msgs($pdo, $chatId, $userId, $days, $now);
    $m = cm_member($pdo, $chatId, $userId);
    if ($mine === 0 || ($m !== null && $m['left_at'] > 0)) { return 0; }
    $st = $pdo->prepare('SELECT COUNT(*) FROM (' . CM_STATS_SUMS . ') t WHERE t.n > ' . $mine
        . ' OR (t.n = ' . $mine . ' AND t.user_id < ' . $userId . ')');
    $st->execute([':c' => $chatId, ':d' => cm_day_from($now, $days)]);
    return (int)$st->fetchColumn() + 1;
}

// Первый день в статистике чата — для «В чате с»; 0 — строк нет.
function cm_stats_first_day(PDO $pdo, int $chatId, int $userId): int {
    $st = $pdo->prepare('SELECT MIN(day) FROM cm_stats WHERE chat_id = :c AND user_id = :u');
    $st->execute([':c' => $chatId, ':u' => $userId]);
    return (int)$st->fetchColumn();
}

// --------------------------------------------------------------------------
//  Команды
// --------------------------------------------------------------------------

// «15 месяц» → [мест, дней]; null — не разобралось. Шаблон тот же, что для
// команды без префикса (CM_BARE_ARGS в parse.php).
function cm_stats_args(string $args): ?array {
    $a = cm_norm(trim($args));
    if (!preg_match(CM_BARE_ARGS['stats_top'], $a)) { return null; }
    $limit = null;
    $days = null;
    foreach (preg_split('~ +~', $a, -1, PREG_SPLIT_NO_EMPTY) as $tok) {
        if (ctype_digit($tok)) {
            $limit = $limit ?? (int)$tok;
        } else {
            $days = $days ?? CM_STATS_PERIODS[$tok];
        }
    }
    $limit = $limit ?? 10;
    return ($limit < 1 || $limit > CM_STATS_TOP_MAX) ? null : [$limit, $days ?? 1];
}

function cm_cmd_stats_top(array $ctx, array $cmd): void {
    if (!cm_need2($ctx)) { return; }
    $a = cm_stats_args($cmd['args']);
    if ($a === null) {
        cm_reply($ctx, cm_t('stats_usage'));
        return;
    }
    [$limit, $days] = $a;
    $pdo = $ctx['pdo'];
    $chatId = (int)$ctx['chat']['chat_id'];
    $now = (int)$ctx['now'];
    $period = cm_t('stats_p_' . $days);
    $top = cm_stats_top($pdo, $chatId, $days, $now, $limit);
    if (!$top) {
        cm_reply($ctx, cm_t('stats_empty', ['period' => $period]), false);
        return;
    }
    $lines = [cm_t('stats_head', ['period' => $period, 'total' => cm_num(cm_stats_total($pdo, $chatId, $days, $now))])];
    foreach ($top as $i => $r) {
        $lines[] = cm_t('stats_line', ['n' => $i + 1, 'name' => cm_user_name($pdo, $r['user_id']), 'msgs' => cm_num($r['msgs'])]);
    }
    cm_reply($ctx, implode("\n", $lines), false);
}

function cm_cmd_stats_me(array $ctx, array $cmd): void {
    if (!cm_need2($ctx)) { return; }
    $args = $cmd['args'];
    $t = cm_target_or_self($ctx, $args);
    if ($t === null) { return; }
    $pdo = $ctx['pdo'];
    $chatId = (int)$ctx['chat']['chat_id'];
    $now = (int)$ctx['now'];
    $uid = (int)$t['id'];
    $place = cm_stats_place($pdo, $chatId, $uid, 0, $now);
    cm_reply($ctx, cm_t('stats_me', [
        'name'  => $t['name'],
        'd'     => cm_num(cm_stats_msgs($pdo, $chatId, $uid, 1, $now)),
        'w'     => cm_num(cm_stats_msgs($pdo, $chatId, $uid, 7, $now)),
        'm'     => cm_num(cm_stats_msgs($pdo, $chatId, $uid, 30, $now)),
        'all'   => cm_num(cm_stats_msgs($pdo, $chatId, $uid, 0, $now)),
        'place' => $place > 0 ? cm_t('stats_place', ['n' => $place]) : '',
    ]), false);
}
```

- [ ] **Step 6: Правки файлов этапа 1**

Edit `public_html/api/lib/cm/db.php` — после строки

```php
const CM_CHAT_TABLES_ROWS = ['cm_punishments', 'cm_rank_log', 'cm_reports', 'cm_timers'];
```

добавить

```php
// Таблицы этапа 2 переезжают, только если его миграция уже выполнена.
const CM_CHAT_TABLES2_PK   = ['cm_stats', 'cm_rep'];
const CM_CHAT_TABLES2_ROWS = ['cm_rep_log', 'cm_awards'];
```

Edit `public_html/api/lib/cm/db.php`, в `cm_migrate()` — фрагмент

```php
    if ($old === 0 || $new === 0 || $old === $new || cm_chat_row($pdo, $old) === null) { return; }
    $pdo->beginTransaction();
    try {
        foreach (array_merge(['cm_chats'], CM_CHAT_TABLES_PK) as $t) {
            $pdo->prepare("DELETE FROM $t WHERE chat_id = :n")->execute([':n' => $new]);
        }
        foreach (array_merge(['cm_chats'], CM_CHAT_TABLES_PK, CM_CHAT_TABLES_ROWS) as $t) {
```

заменить на

```php
    if ($old === 0 || $new === 0 || $old === $new || cm_chat_row($pdo, $old) === null) { return; }
    $pk = CM_CHAT_TABLES_PK;
    $rows = CM_CHAT_TABLES_ROWS;
    if (cm_ready2($pdo)) {
        $pk = array_merge($pk, CM_CHAT_TABLES2_PK);
        $rows = array_merge($rows, CM_CHAT_TABLES2_ROWS);
    }
    $pdo->beginTransaction();
    try {
        foreach (array_merge(['cm_chats'], $pk) as $t) {
            $pdo->prepare("DELETE FROM $t WHERE chat_id = :n")->execute([':n' => $new]);
        }
        foreach (array_merge(['cm_chats'], $pk, $rows) as $t) {
```

Edit `public_html/api/lib/cm/parse.php` — перед строкой

```php
    'помощь'             => ['help', null, 0, true, false],
```

вставить

```php
    'стата'              => ['stats_top', 'статистика', 0, false, false],
    'моя стата'          => ['stats_me', 'статистика', 0, false, false],
```

Edit `public_html/api/lib/cm/parse.php`: строку

```php
const CM_PREFIXES = ['!', '.', '/'];
```

заменить на

```php
const CM_PREFIXES = ['!', '.', '/'];

// Команды этапа 2, у которых аргументы есть по смыслу, а цель необязательна.
// Без префикса такая команда — только если второй строки нет, а остаток
// первой без цели (cm_target) целиком подходит под шаблон (после cm_norm).
// Иначе это обычная речь: «стата норм», «награды раздали».
const CM_BARE_ARGS = [
    'stats_top'   => '~^(?:(?:[0-9]{1,2}|день|неделя|месяц|все)(?: +|$)){0,2}$~u',
    'stats_me'    => '~^$~',
];

function cm_bare_ok(array $ctx, array $cmd): bool {
    $re = CM_BARE_ARGS[$cmd['handler']] ?? null;
    if ($re === null) { return true; }
    if (trim($cmd['body']) !== '') { return false; }
    $a = $cmd['args'];
    cm_target($ctx['pdo'], $ctx['msg'], $a);
    return preg_match($re, cm_norm(trim($a))) === 1;
}
```

Edit `public_html/api/lib/cm/router.php` — фрагмент

```php
 *  - без ранга и без префикса — молча, как обычная речь.
 */
function cm_run_command(array $ctx, array $cmd): bool {
    if (!$cmd['prefixed'] && $cmd['exact']
```

заменить на

```php
 *  - без ранга и без префикса — молча, как обычная речь;
 *  - команда из CM_BARE_ARGS без префикса — только если остаток строки без
 *    цели подходит под её шаблон (cm_bare_ok, parse.php).
 */
function cm_run_command(array $ctx, array $cmd): bool {
    if (!$cmd['prefixed'] && !cm_bare_ok($ctx, $cmd)) { return false; }
    if (!$cmd['prefixed'] && $cmd['exact']
```

(дальше строка остаётся как была: `&& ($cmd['args'] !== '' || trim($cmd['body']) !== '')) { return false; }`).

Edit `public_html/api/lib/cm/ranks.php`: в `CM_SECTIONS` строку

```php
                     'правила', 'репорты', 'чистка', 'заметки', 'таймеры'];
```

заменить на

```php
                     'правила', 'репорты', 'чистка', 'заметки', 'таймеры', 'статистика'];
```

Edit `public_html/api/lib/cm/texts.php` — фрагмент

```php
    // общие команды
    'whoami'
```

заменить на

```php
    // этап 2: общее
    'not_ready2'       => 'Эта часть бота ещё не настроена.',
    // статистика
    'stats_usage'      => 'Так: «стата», «стата 20», «стата неделя», «стата 15 месяц», «стата всё» (мест — до 50).',
    'stats_head'       => '📊 Активность за {period} (всего {total}):',
    'stats_line'       => '{n}. {name} — {msgs}',
    'stats_empty'      => 'За {period} сообщений нет.',
    'stats_p_1'        => 'сегодня',
    'stats_p_7'        => 'неделю',
    'stats_p_30'       => 'месяц',
    'stats_p_0'        => 'всё время',
    'stats_me'         => "📊 {name}\nСегодня: {d} · неделя: {w} · месяц: {m} · всего: {all}{place}",
    'stats_place'      => "\nМесто в топе за всё время: {n}",
    // общие команды
    'whoami'
```

Edit `public_html/api/lib/cm/cm.php`: строку

```php
          'welcome', 'reports', 'cleanup', 'notes', 'timers', 'router', 'cron'] as $cm_part) {
```

заменить на

```php
          'welcome', 'reports', 'cleanup', 'notes', 'timers', 'stats', 'router', 'cron'] as $cm_part) {
```

- [ ] **Step 7: Тест проходит, этап 1 цел**

Run: `/c/xampp/php/php.exe tests/cm_stats_test.php`
Expected: все `ok - …`, в конце `70 assertions, 0 failures`.

Run:
```bash
for t in tests/cm_*_test.php tests/php74_compat_test.php; do /c/xampp/php/php.exe -d extension=gd "$t" > /dev/null 2>&1 || echo "FAIL $t"; done; echo done
```
Expected: одна строка `done`.

- [ ] **Step 8: Commit**

```bash
git add docs/migrations/2026-11-08-chat-manager-activity.sql tests/cm_schema.php tests/cm_helpers.php tests/cm_stats_test.php public_html/api/lib/cm/stats.php public_html/api/lib/cm/db.php public_html/api/lib/cm/parse.php public_html/api/lib/cm/router.php public_html/api/lib/cm/ranks.php public_html/api/lib/cm/texts.php public_html/api/lib/cm/cm.php
git commit -m "feat(cm): статистика сообщений и таблицы этапа 2" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git show --stat HEAD
```
Expected: 11 файлов, ни одной строки `Bin`.

---

### Task 2: Репутация

**Files:**
- Create: `public_html/api/lib/cm/rep.php`
- Modify: `public_html/api/lib/cm/parse.php`, `ranks.php`, `texts.php`, `cm.php`
- Test: `tests/cm_rep_test.php`

**Interfaces:**
- Consumes: `cm_ready2`, `cm_need2`, `cm_target_or_self`, `CM_BARE_ARGS` (Task 1); этап 1: `cm_reply_of(array $msg): ?array` (ответ, без корня темы форума), `cm_need_target(array $ctx, string &$args): ?array` (нет цели — ответ `need_target`/`target_unknown` и `null`), `cm_need_level(array $chat, ?string $section, int $default): int`, `cm_section_default(string $section): int`, `cm_user_name`, `cm_display_name`.
- Produces:
  - `CM_REP_PAIR_GAP` (86400), `CM_REP_DAY_MAX` (20), `CM_REP_WORDS`, `CM_TG_SERVICE_ID` (777000)
  - `cm_rep_score(PDO $pdo, int $chatId, int $userId): int`
  - `cm_rep_vote(array $ctx, int $toId, bool $toBot, int $delta): string` — `''` записан, иначе ключ текста (`target_bot`, `target_self`, `rep_pair_wait`, `rep_day_full`)
  - `cm_rep_done(array $ctx, string $toName, int $toId, int $delta): void`
  - `cm_rep_try(array $ctx): bool` — для роутера (Task 5): `true` — голос (дальше не идёт), `false` — обычное сообщение
  - обработчики `cm_cmd_rep_plus`, `cm_cmd_rep_minus`, `cm_cmd_rep_show`, `cm_cmd_rep_top`
  - Тексты: `rep_up`, `rep_down`, `rep_pair_wait`, `rep_day_full`, `rep_show`, `rep_top_head`, `rep_top_line`, `rep_top_empty`

- [ ] **Step 1: Падающий тест**

Write `tests/cm_rep_test.php`:

```php
<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';

// Репутация (этап 2): api/lib/cm/rep.php.

// Ответ на сообщение человека $to с именем $name.
function rp_reply(int $to, string $name = 'Петя', array $from = []): array {
    return ['reply_to_message' => ['message_id' => 70, 'from' => $from + ['id' => $to, 'is_bot' => false, 'first_name' => $name]]];
}

function rp_score(PDO $pdo, int $userId): int {
    return cm_rep_score($pdo, CM_CHAT, $userId);
}

test('ответ «+» и «спасибо» — голос, ответ бота временный', function () {
    $pdo = cm_test_pdo();
    $log = [];
    assert_eq(true, cm_rep_try(cm_ctx_for($pdo, $log, 7, '+', rp_reply(20))));
    assert_eq(1, rp_score($pdo, 20));
    assert_eq('👍 U7 → Петя: +1 (репутация 1)', cm_last_text($log));
    assert_eq(1, (int)$pdo->query('SELECT COUNT(*) FROM cm_queue')->fetchColumn(), 'удалится через минуту');
    assert_eq(true, cm_rep_try(cm_ctx_for($pdo, $log, 8, 'Спасибо!', rp_reply(20))));
    assert_eq(true, cm_rep_try(cm_ctx_for($pdo, $log, 9, "спс\nвыручил", rp_reply(20))));
    assert_eq(3, rp_score($pdo, 20));
    assert_eq(true, cm_rep_try(cm_ctx_for($pdo, $log, 10, '-', rp_reply(20))));
    assert_eq('👎 U10 → Петя: -1 (репутация 2)', cm_last_text($log));
});

test('не голос: без ответа, лишние слова в первой строке', function () {
    $pdo = cm_test_pdo();
    $log = [];
    assert_eq(false, cm_rep_try(cm_ctx_for($pdo, $log, 7, '+')));
    assert_eq(false, cm_rep_try(cm_ctx_for($pdo, $log, 7, 'спасибо большое', rp_reply(20))));
    assert_eq(false, cm_rep_try(cm_ctx_for($pdo, $log, 7, '+ за помощь', rp_reply(20))));
    assert_eq(false, cm_rep_try(cm_ctx_for($pdo, $log, 7, '+реп', rp_reply(20))));
    assert_eq(0, rp_score($pdo, 20));
    assert_eq([], cm_calls($log, 'sendMessage'));
});

test('себе, ботам и служебному Telegram — молча, но это голос', function () {
    $pdo = cm_test_pdo();
    $log = [];
    assert_eq(true, cm_rep_try(cm_ctx_for($pdo, $log, 7, '+', rp_reply(7, 'U7'))));
    assert_eq(true, cm_rep_try(cm_ctx_for($pdo, $log, 7, '+', rp_reply(555, 'Бот', ['is_bot' => true]))));
    assert_eq(true, cm_rep_try(cm_ctx_for($pdo, $log, 7, '+', rp_reply(777000, 'Telegram'))));
    assert_eq([], cm_calls($log, 'sendMessage'));
    assert_eq(0, (int)$pdo->query('SELECT COUNT(*) FROM cm_rep_log')->fetchColumn());
});

test('одному человеку — раз в 24 часа, лишний голос молча', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_rep_try(cm_ctx_for($pdo, $log, 7, '+', rp_reply(20)));
    $n = count(cm_calls($log, 'sendMessage'));
    assert_eq(true, cm_rep_try(cm_ctx_for($pdo, $log, 7, '+', rp_reply(20), [], CM_NOW + 86399)));
    assert_eq($n, count(cm_calls($log, 'sendMessage')));
    assert_eq(1, rp_score($pdo, 20));
    cm_rep_try(cm_ctx_for($pdo, $log, 7, '+', rp_reply(20), [], CM_NOW + 86401));
    assert_eq(2, rp_score($pdo, 20), 'через сутки — снова можно');
});

test('не больше 20 голосов за 24 часа от одного человека', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ins = $pdo->prepare('INSERT INTO cm_rep_log (chat_id, from_id, to_id, delta, at) VALUES (:c, 7, :t, 1, :a)');
    for ($i = 0; $i < 20; $i++) { $ins->execute([':c' => CM_CHAT, ':t' => 100 + $i, ':a' => CM_NOW - 3600]); }
    assert_eq(true, cm_rep_try(cm_ctx_for($pdo, $log, 7, '+', rp_reply(20))));
    assert_eq(0, rp_score($pdo, 20));
    assert_eq([], cm_calls($log, 'sendMessage'), 'ответом — молча');
    cm_person($pdo, 20, 'petya', 'Петя');
    cm_do(cm_ctx_for($pdo, $log, 7), '+реп @petya');
    assert_eq(cm_t('rep_day_full', ['max' => 20]), cm_last_text($log), 'командой — с пояснением');
    cm_rep_try(cm_ctx_for($pdo, $log, 7, '+', rp_reply(20), [], CM_NOW + 82801));
    assert_eq(1, rp_score($pdo, 20), 'старые голоса вышли из суток');
});

test('+реп и -реп: цель, отказы с пояснением', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 20, 'petya', 'Петя');
    $ctx = cm_ctx_for($pdo, $log, 7);
    cm_do($ctx, '+реп @petya');
    assert_eq('👍 U7 → Петя: +1 (репутация 1)', cm_last_text($log));
    cm_do($ctx, '-реп @petya');
    assert_eq(cm_t('rep_pair_wait'), cm_last_text($log));
    cm_do($ctx, '+реп');
    assert_eq(cm_t('need_target'), cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, 7, '', rp_reply(7, 'U7')), '+реп');
    assert_eq(cm_t('target_self'), cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, 7, '', rp_reply(555, 'Бот', ['is_bot' => true])), '-реп');
    assert_eq(cm_t('target_bot'), cm_last_text($log));
});

test('репа: своя, чужая; топ репы без нулей и ушедших', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 7, 'user7');
    cm_person($pdo, 20, 'petya', 'Петя');
    cm_person($pdo, 21, 'kolya', 'Коля');
    cm_person($pdo, 22, 'olya', 'Оля');
    $ins = $pdo->prepare('INSERT INTO cm_rep (chat_id, user_id, score) VALUES (:c, :u, :s)');
    foreach ([7 => 3, 20 => 15, 21 => -2, 22 => 0, 23 => 40] as $u => $s) { $ins->execute([':c' => CM_CHAT, ':u' => $u, ':s' => $s]); }
    cm_member_set($pdo, CM_CHAT, 23, ['left_at' => CM_NOW]);
    $ctx = cm_ctx_for($pdo, $log, 7);
    cm_do($ctx, 'репа');
    assert_eq('⭐ U7: репутация 3', cm_last_text($log));
    cm_do($ctx, 'репа @petya');
    assert_eq('⭐ Петя: репутация 15', cm_last_text($log));
    cm_do($ctx, 'топ репы');
    assert_eq("⭐ Лучшая репутация:\n1. Петя — 15\n2. U7 — 3\n3. Коля — -2", cm_last_text($log));
    cm_do(cm_ctx_for(cm_test_pdo(), $log, 7), 'топ репы');
    assert_eq(cm_t('rep_top_empty'), cm_last_text($log));
});

test('без префикса: «репа» ответом — команда, «репа сварилась» — речь', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, 7, 'репа', rp_reply(20));
    assert_eq(true, cm_run_command($ctx, cm_parse('репа', CM_BOT)));
    $ctx = cm_ctx_for($pdo, $log, 7, 'репа сварилась');
    assert_eq(false, cm_run_command($ctx, cm_parse('репа сварилась', CM_BOT)));
    $ctx = cm_ctx_for($pdo, $log, 7, '+реп');
    assert_eq(false, cm_run_command($ctx, cm_parse('+реп', CM_BOT)), 'без цели и префикса — речь');
});

test('дк репутация 6: «+» — обычное сообщение, команды выключены', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), 'дк репутация 6');
    assert_eq(false, cm_rep_try(cm_ctx_for($pdo, $log, 7, '+', rp_reply(20))));
    $ctx = cm_ctx_for($pdo, $log, 7, '!репа');
    cm_run_command($ctx, cm_parse('!репа', CM_BOT));
    assert_eq(cm_t('cmd_off'), cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), 'дк репутация 2');
    assert_eq(false, cm_rep_try(cm_ctx_for($pdo, $log, 7, '+', rp_reply(20))), 'ранг ниже нужного — не голос');
    cm_lvl($pdo, 7, 2);
    assert_eq(true, cm_rep_try(cm_ctx_for($pdo, $log, 7, '+', rp_reply(20))));
});

test('нет таблиц этапа 2: «+» — обычное сообщение, команды — «ещё не настроено»', function () {
    $pdo = cm_test_pdo();
    $pdo->exec('DROP TABLE cm_stats');
    $log = [];
    assert_eq(false, cm_rep_try(cm_ctx_for($pdo, $log, 7, '+', rp_reply(20))));
    cm_do(cm_ctx_for($pdo, $log, 7), 'топ репы');
    assert_eq(cm_t('not_ready2'), cm_last_text($log));
});

run_tests();
```

- [ ] **Step 2: Убедиться, что тест падает**

Run: `/c/xampp/php/php.exe tests/cm_rep_test.php`
Expected: FAIL — `ERROR - ответ «+» и «спасибо» — голос, ответ бота временный: Call to undefined function cm_rep_try()`.

- [ ] **Step 3: Репутация**

Write `public_html/api/lib/cm/rep.php`:

```php
<?php
// Репутация в чате (этап 2): ответ «+», «-», «спасибо» на чужое сообщение
// или команды +реп / -реп. Одному человеку — раз в 24 часа, всего — не больше
// 20 голосов за 24 часа от одного человека в чате.

const CM_REP_PAIR_GAP = 86400;
const CM_REP_DAY_MAX  = 20;
// Первая строка ответа целиком (после cm_norm и без «!») → голос.
const CM_REP_WORDS    = ['+' => 1, '-' => -1, 'спасибо' => 1, 'спс' => 1, 'благодарю' => 1];
// Служебный аккаунт Telegram: от его имени приходят автопересылки из канала.
const CM_TG_SERVICE_ID = 777000;

function cm_rep_score(PDO $pdo, int $chatId, int $userId): int {
    $st = $pdo->prepare('SELECT score FROM cm_rep WHERE chat_id = :c AND user_id = :u');
    $st->execute([':c' => $chatId, ':u' => $userId]);
    return (int)$st->fetchColumn();
}

// Записать голос автора контекста. '' — записан, иначе ключ текста отказа.
function cm_rep_vote(array $ctx, int $toId, bool $toBot, int $delta): string {
    $pdo = $ctx['pdo'];
    $chatId = (int)$ctx['chat']['chat_id'];
    $from = (int)$ctx['actor'];
    $now = (int)$ctx['now'];
    if ($toBot || $toId === CM_TG_SERVICE_ID) { return 'target_bot'; }
    if ($toId <= 0 || $toId === $from) { return 'target_self'; }
    $since = $now - CM_REP_PAIR_GAP;
    $st = $pdo->prepare('SELECT COUNT(*) FROM cm_rep_log WHERE chat_id = :c AND from_id = :f AND to_id = :t AND at > :s');
    $st->execute([':c' => $chatId, ':f' => $from, ':t' => $toId, ':s' => $since]);
    if ((int)$st->fetchColumn() > 0) { return 'rep_pair_wait'; }
    $st = $pdo->prepare('SELECT COUNT(*) FROM cm_rep_log WHERE chat_id = :c AND from_id = :f AND at > :s');
    $st->execute([':c' => $chatId, ':f' => $from, ':s' => $since]);
    if ((int)$st->fetchColumn() >= CM_REP_DAY_MAX) { return 'rep_day_full'; }
    $pdo->prepare('INSERT INTO cm_rep_log (chat_id, from_id, to_id, delta, at) VALUES (:c, :f, :t, :d, :a)')
        ->execute([':c' => $chatId, ':f' => $from, ':t' => $toId, ':d' => $delta, ':a' => $now]);
    $p = [':c' => $chatId, ':u' => $toId, ':d' => $delta];
    $up = $pdo->prepare('UPDATE cm_rep SET score = score + :d WHERE chat_id = :c AND user_id = :u');
    $up->execute($p);
    if ($up->rowCount() > 0) { return ''; }
    try {
        $pdo->prepare('INSERT INTO cm_rep (chat_id, user_id, score) VALUES (:c, :u, :d)')->execute($p);
    } catch (PDOException $e) {
        $up->execute($p);   // параллельный голос уже завёл строку
    }
    return '';
}

// Временный ответ: «👍 Вася → Петя: +1 (репутация 15)».
function cm_rep_done(array $ctx, string $toName, int $toId, int $delta): void {
    $from = is_array($ctx['msg']['from'] ?? null) ? $ctx['msg']['from'] : [];
    cm_reply($ctx, cm_t($delta > 0 ? 'rep_up' : 'rep_down', ['from' => cm_display_name($from), 'to' => $toName,
        'score' => cm_rep_score($ctx['pdo'], (int)$ctx['chat']['chat_id'], $toId)]));
}

/**
 * Голос ответом. Вызывает роутер до разбора команд. true — это было
 * голосование, даже отклонённое: сообщение дальше не идёт. Отказ — молча:
 * это реплика в живом разговоре. false — обычное сообщение (не ответ, лишние
 * слова, раздел выключен или ранга мало, нет таблиц этапа 2).
 */
function cm_rep_try(array $ctx): bool {
    $msg = $ctx['msg'];
    $reply = cm_reply_of($msg);
    if ($reply === null || !is_array($reply['from'] ?? null)) { return false; }
    $first = explode("\n", (string)($msg['text'] ?? ''))[0];
    $word = cm_norm(trim(str_replace('!', '', $first)));
    if (!isset(CM_REP_WORDS[$word])) { return false; }
    $need = cm_need_level($ctx['chat'], 'репутация', cm_section_default('репутация'));
    if ($need >= 6 || (int)$ctx['rank'] < $need || !cm_ready2($ctx['pdo'])) { return false; }
    $f = $reply['from'];
    $delta = CM_REP_WORDS[$word];
    if (cm_rep_vote($ctx, (int)($f['id'] ?? 0), !empty($f['is_bot']), $delta) === '') {
        cm_rep_done($ctx, cm_display_name($f), (int)$f['id'], $delta);
    }
    return true;
}

// --------------------------------------------------------------------------
//  Команды
// --------------------------------------------------------------------------

function cm_cmd_rep_plus(array $ctx, array $cmd): void { cm_rep_cmd($ctx, $cmd, 1); }
function cm_cmd_rep_minus(array $ctx, array $cmd): void { cm_rep_cmd($ctx, $cmd, -1); }

// Командой отказ поясняется: человек сам попросил.
function cm_rep_cmd(array $ctx, array $cmd, int $delta): void {
    if (!cm_need2($ctx)) { return; }
    $args = $cmd['args'];
    $t = cm_need_target($ctx, $args);
    if ($t === null) { return; }
    $err = cm_rep_vote($ctx, (int)$t['id'], !empty($t['is_bot']), $delta);
    if ($err !== '') {
        cm_reply($ctx, cm_t($err, ['max' => CM_REP_DAY_MAX]));
        return;
    }
    cm_rep_done($ctx, (string)$t['name'], (int)$t['id'], $delta);
}

function cm_cmd_rep_show(array $ctx, array $cmd): void {
    if (!cm_need2($ctx)) { return; }
    $args = $cmd['args'];
    $t = cm_target_or_self($ctx, $args);
    if ($t === null) { return; }
    cm_reply($ctx, cm_t('rep_show', ['name' => $t['name'],
        'score' => cm_rep_score($ctx['pdo'], (int)$ctx['chat']['chat_id'], (int)$t['id'])]));
}

// 10 лучших с ненулевой репутацией, кто не покинул чат.
function cm_cmd_rep_top(array $ctx, array $cmd): void {
    if (!cm_need2($ctx)) { return; }
    $pdo = $ctx['pdo'];
    $st = $pdo->prepare('SELECT r.user_id, r.score FROM cm_rep r
        LEFT JOIN cm_members m ON m.chat_id = r.chat_id AND m.user_id = r.user_id
        WHERE r.chat_id = :c AND r.score <> 0 AND (m.left_at IS NULL OR m.left_at = 0)
        ORDER BY r.score DESC, r.user_id LIMIT 10');
    $st->execute([':c' => $ctx['chat']['chat_id']]);
    $lines = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $i => $r) {
        $lines[] = cm_t('rep_top_line', ['n' => $i + 1, 'name' => cm_user_name($pdo, (int)$r['user_id']), 'score' => (int)$r['score']]);
    }
    cm_reply($ctx, $lines ? cm_t('rep_top_head') . "\n" . implode("\n", $lines) : cm_t('rep_top_empty'), false);
}
```

- [ ] **Step 4: Команды, раздел, тексты, подключение**

Edit `public_html/api/lib/cm/parse.php` — перед строкой

```php
    'помощь'             => ['help', null, 0, true, false],
```

вставить

```php
    '+реп'               => ['rep_plus', 'репутация', 0, false, true],
    '-реп'               => ['rep_minus', 'репутация', 0, false, true],
    'репа'               => ['rep_show', 'репутация', 0, false, false],
    'топ репы'           => ['rep_top', 'репутация', 0, true, false],
```

Edit `public_html/api/lib/cm/parse.php`, в `CM_BARE_ARGS` — фрагмент

```php
    'stats_me'    => '~^$~',
];
```

заменить на

```php
    'stats_me'    => '~^$~',
    'rep_show'    => '~^$~',
];
```

Edit `public_html/api/lib/cm/ranks.php`: строку

```php
                     'правила', 'репорты', 'чистка', 'заметки', 'таймеры', 'статистика'];
```

заменить на

```php
                     'правила', 'репорты', 'чистка', 'заметки', 'таймеры', 'статистика',
                     'репутация'];
```

Edit `public_html/api/lib/cm/texts.php`: после строки

```php
    'stats_place'      => "\nМесто в топе за всё время: {n}",
```

добавить

```php
    // репутация
    'rep_up'           => '👍 {from} → {to}: +1 (репутация {score})',
    'rep_down'         => '👎 {from} → {to}: -1 (репутация {score})',
    'rep_pair_wait'    => 'Этому человеку голос — не чаще раза в сутки.',
    'rep_day_full'     => 'Не больше {max} голосов за сутки.',
    'rep_show'         => '⭐ {name}: репутация {score}',
    'rep_top_head'     => '⭐ Лучшая репутация:',
    'rep_top_line'     => '{n}. {name} — {score}',
    'rep_top_empty'    => 'Репутации пока ни у кого нет.',
```

Edit `public_html/api/lib/cm/cm.php`: в массиве частей `'timers', 'stats', 'router', 'cron'] as $cm_part) {` заменить на `'timers', 'stats', 'rep', 'router', 'cron'] as $cm_part) {`.

- [ ] **Step 5: Тест проходит, остальное цело**

Run: `/c/xampp/php/php.exe tests/cm_rep_test.php`
Expected: `47 assertions, 0 failures`.

Run:
```bash
for t in tests/cm_*_test.php tests/php74_compat_test.php; do /c/xampp/php/php.exe -d extension=gd "$t" > /dev/null 2>&1 || echo "FAIL $t"; done; echo done
```
Expected: одна строка `done`.

- [ ] **Step 6: Commit**

```bash
git add public_html/api/lib/cm/rep.php tests/cm_rep_test.php public_html/api/lib/cm/parse.php public_html/api/lib/cm/ranks.php public_html/api/lib/cm/texts.php public_html/api/lib/cm/cm.php
git commit -m "feat(cm): репутация — голоса ответом и командами" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git show --stat HEAD
```
Expected: 6 файлов, без `Bin`.

---

### Task 3: Награды

**Files:**
- Create: `public_html/api/lib/cm/awards.php`
- Modify: `public_html/api/lib/cm/parse.php`, `ranks.php`, `texts.php`, `cm.php`
- Test: `tests/cm_awards_test.php`

**Interfaces:**
- Consumes: `cm_need2`, `cm_target_or_self` (Task 1); этап 1: `cm_need_target`, `cm_take_term(string &$rest): ?int` (`null` — срока нет, `0` — навсегда, `-1` — вне 30 с…366 дней; срок съедается из `$rest`), `cm_date(int $ts): string` (`d.m H:i` МСК), `cm_norm`.
- Produces:
  - `CM_AWARD_ICONS` (1–8: 🎗 🏅 🥉 🥈 🥇 🎖 🏆 👑), `CM_AWARDS_MAX` (20), `CM_AWARD_REASON_MAX` (100), `CM_AWARDS_BRIEF` (5)
  - `cm_awards_active(PDO $pdo, int $chatId, int $userId, int $now): array` — `[['id' => int, 'level' => int, 'reason' => string, 'until_at' => int], …]`, старшие раньше
  - `cm_award_line(array $a, bool $full): string`, `cm_awards_brief(array $list): string` — для профиля (Task 4)
  - обработчики `cm_cmd_award_add`, `cm_cmd_award_del`, `cm_cmd_awards_show`
  - Тексты: `award_usage`, `award_done`, `award_until`, `award_full`, `award_removed`, `award_missing`, `awards_head`, `awards_none`, `awards_more`

- [ ] **Step 1: Падающий тест**

Write `tests/cm_awards_test.php`:

```php
<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';

// Награды (этап 2): api/lib/cm/awards.php.

function aw_count(PDO $pdo): int {
    return (int)$pdo->query('SELECT COUNT(*) FROM cm_awards')->fetchColumn();
}

test('+награда: степень, причина, срок «на 30 дней»; сообщение постоянное', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 20, 'petya', 'Петя');
    $ctx = cm_ctx_for($pdo, $log, CM_OWNER);
    cm_do($ctx, '+награда 5 @petya Лучший трейдер');
    assert_eq('🥇 Петя получает награду 5-й степени: Лучший трейдер', cm_last_text($log));
    assert_eq(0, (int)$pdo->query('SELECT COUNT(*) FROM cm_queue')->fetchColumn(), 'не удаляется');
    cm_do($ctx, '+награда 2 @petya на 30 дней Помощь новичкам');
    assert_eq('🏅 Петя получает награду 2-й степени: Помощь новичкам (до ' . cm_date(CM_NOW + 2592000) . ' МСК)', cm_last_text($log));
    cm_do($ctx, '+награда 1 @petya на память');
    assert_eq('🎗 Петя получает награду 1-й степени: на память', cm_last_text($log), '«на» без срока — часть причины');
    $r = $pdo->query('SELECT level, reason, by_id, until_at FROM cm_awards ORDER BY id')->fetchAll(PDO::FETCH_NUM);
    assert_eq([[5, 'Лучший трейдер', CM_OWNER, 0], [2, 'Помощь новичкам', CM_OWNER, CM_NOW + 2592000], [1, 'на память', CM_OWNER, 0]],
        array_map(function ($x) { return [(int)$x[0], (string)$x[1], (int)$x[2], (int)$x[3]]; }, $r));
});

test('+награда ответом; подсказки и отказы', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $reply = ['reply_to_message' => ['message_id' => 70, 'from' => ['id' => 20, 'first_name' => 'Петя']]];
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER, '', $reply), '+награда 8 Легенда чата');
    assert_eq('👑 Петя получает награду 8-й степени: Легенда чата', cm_last_text($log));
    $ctx = cm_ctx_for($pdo, $log, CM_OWNER, '', $reply);
    foreach (['+награда 9 причина', '+награда 3', '+награда причина', '+награда 3 ' . str_repeat('я', 101)] as $bad) {
        cm_do($ctx, $bad);
        assert_eq(cm_t('award_usage'), cm_last_text($log), $bad);
    }
    cm_do($ctx, '+награда 3 на 400 дней причина');
    assert_eq(cm_t('term_bad'), cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), '+награда 3 причина');
    assert_eq(cm_t('need_target'), cm_last_text($log));
    $self = ['reply_to_message' => ['message_id' => 71, 'from' => ['id' => CM_OWNER, 'first_name' => 'Owner']]];
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER, '', $self), '+награда 3 себе');
    assert_eq(cm_t('target_self'), cm_last_text($log));
    $bot = ['reply_to_message' => ['message_id' => 72, 'from' => ['id' => 555, 'is_bot' => true, 'first_name' => 'Бот']]];
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER, '', $bot), '+награда 3 боту');
    assert_eq(cm_t('target_bot'), cm_last_text($log));
    assert_eq(1, aw_count($pdo));
});

test('не больше 20 действующих наград у человека', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 20, 'petya', 'Петя');
    $ins = $pdo->prepare("INSERT INTO cm_awards (chat_id, user_id, level, reason, by_id, created_at, until_at, removed_at)
        VALUES (:c, 20, 1, 'x', 1, 0, :u, :r)");
    for ($i = 0; $i < 19; $i++) { $ins->execute([':c' => CM_CHAT, ':u' => 0, ':r' => 0]); }
    $ins->execute([':c' => CM_CHAT, ':u' => CM_NOW - 1, ':r' => 0]);   // истекла — не считается
    $ins->execute([':c' => CM_CHAT, ':u' => 0, ':r' => CM_NOW - 1]);   // снята — не считается
    $ctx = cm_ctx_for($pdo, $log, CM_OWNER);
    cm_do($ctx, '+награда 1 @petya двадцатая');
    assert_eq(0, strpos(cm_last_text($log), '🎗 Петя получает'));
    cm_do($ctx, '+награда 1 @petya двадцать первая');
    assert_eq(cm_t('award_full', ['name' => 'Петя', 'max' => 20]), cm_last_text($log));
});

test('награды: свои и чужие, по убыванию степени, с номерами; истёкшая не видна', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 20, 'petya', 'Петя');
    $o = cm_ctx_for($pdo, $log, CM_OWNER);
    cm_do($o, '+награда 2 @petya Помощь новичкам');
    cm_do($o, '+награда 5 @petya на 1 день Лучший трейдер');
    cm_do($o, '+награда 3 @petya Старожил');
    cm_do(cm_ctx_for($pdo, $log, 7), 'награды @petya');
    assert_eq("🏅 Награды Петя:\n#2 🥇 Лучший трейдер (до " . cm_date(CM_NOW + 86400) . " МСК)\n#3 🥉 Старожил\n#1 🏅 Помощь новичкам",
        cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, 20, '', [], [], CM_NOW + 86400), 'награды');
    assert_eq("🏅 Награды U20:\n#3 🥉 Старожил\n#1 🏅 Помощь новичкам", cm_last_text($log), 'через сутки срочная пропала');
    cm_do(cm_ctx_for($pdo, $log, 7), 'награды');
    assert_eq(cm_t('awards_none', ['name' => 'U7']), cm_last_text($log));
});

test('-награда N снимает; чужой номер и повтор — «нет такой»', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 20, 'petya', 'Петя');
    $o = cm_ctx_for($pdo, $log, CM_OWNER);
    cm_do($o, '+награда 4 @petya Серебро');
    $pdo->exec("INSERT INTO cm_awards (chat_id, user_id, level, reason, by_id, created_at) VALUES (-999, 20, 1, 'чужая', 1, 0)");
    cm_do($o, '-награда 1');
    assert_eq(cm_t('award_removed', ['id' => 1]), cm_last_text($log));
    assert_eq(CM_NOW, (int)$pdo->query('SELECT removed_at FROM cm_awards WHERE id = 1')->fetchColumn());
    cm_do($o, '-награда #1');
    assert_eq(cm_t('award_missing', ['id' => 1]), cm_last_text($log));
    cm_do($o, '-награда 2');
    assert_eq(cm_t('award_missing', ['id' => 2]), cm_last_text($log), 'награда другого чата');
    assert_eq([], cm_awards_active($pdo, CM_CHAT, 20, CM_NOW));
});

test('кратко для профиля: пять старших и «и ещё N»', function () {
    $list = [];
    foreach ([8, 7, 6, 5, 4, 3, 2] as $i => $lvl) { $list[] = ['id' => $i + 1, 'level' => $lvl, 'reason' => 'н' . $lvl, 'until_at' => 0]; }
    assert_eq('👑 н8, 🏆 н7, 🎖 н6, 🥇 н5, 🥈 н4 и ещё 2', cm_awards_brief($list));
    assert_eq('🥉 н3', cm_awards_brief([$list[5]]));
});

test('ранг: выдают с 3, «дк награды 2» — с 2; смотрят все и без префикса', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 20, 'petya', 'Петя');
    cm_lvl($pdo, 10, 2);
    $run = function (int $actor, string $text, array $extra = []) use ($pdo, &$log): bool {
        return cm_run_command(cm_ctx_for($pdo, $log, $actor, $text, $extra), cm_parse($text, CM_BOT));
    };
    $run(10, '!+награда 3 @petya рано');
    assert_eq(cm_t('no_rank', ['need' => 3]), cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), 'дк награды 2');
    $run(10, '+награда 3 @petya вовремя');
    assert_eq('🥉 Петя получает награду 3-й степени: вовремя', cm_last_text($log));
    $run(10, '!-награда 1');
    assert_eq(cm_t('award_removed', ['id' => 1]), cm_last_text($log), 'снимает тот же ранг, что выдаёт');
    $reply = ['reply_to_message' => ['message_id' => 70, 'from' => ['id' => 20, 'first_name' => 'Петя']]];
    assert_eq(true, $run(7, 'награды', $reply));
    assert_eq(false, $run(7, 'награды раздали'));
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), 'дк награды 6');
    assert_eq(true, $run(7, 'награды'), 'выключили выдачу — смотреть можно');
});

test('нет таблиц этапа 2 — «ещё не настроено»', function () {
    $pdo = cm_test_pdo();
    $pdo->exec('DROP TABLE cm_stats');
    $log = [];
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), 'награды');
    assert_eq(cm_t('not_ready2'), cm_last_text($log));
});

run_tests();
```

- [ ] **Step 2: Убедиться, что тест падает**

Run: `/c/xampp/php/php.exe tests/cm_awards_test.php`
Expected: FAIL — `ERROR - +награда: …: не команда: +награда 5 @petya Лучший трейдер`.

- [ ] **Step 3: Награды**

Write `public_html/api/lib/cm/awards.php`:

```php
<?php
// Награды (этап 2): степени 1–8, причина обязательна, срок — по желанию
// («на 30 дней»). Выдают и снимают с ранга 3: раздел «награды», «дк награды 2»
// пускает с ранга 2 — отдельных «награждающих» нет. Смотрят все: команда
// «награды» вне раздела, как «заметки» и «правила» в этапе 1.

const CM_AWARD_ICONS      = [1 => '🎗', 2 => '🏅', 3 => '🥉', 4 => '🥈', 5 => '🥇', 6 => '🎖', 7 => '🏆', 8 => '👑'];
const CM_AWARDS_MAX       = 20;
const CM_AWARD_REASON_MAX = 100;
const CM_AWARDS_BRIEF     = 5;

// Действующие награды (не сняты, не истекли): старшие раньше, при равной
// степени — новые раньше.
function cm_awards_active(PDO $pdo, int $chatId, int $userId, int $now): array {
    $st = $pdo->prepare('SELECT id, level, reason, until_at FROM cm_awards WHERE chat_id = :c AND user_id = :u
        AND removed_at = 0 AND (until_at = 0 OR until_at > :n) ORDER BY level DESC, id DESC');
    $st->execute([':c' => $chatId, ':u' => $userId, ':n' => $now]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['id' => (int)$r['id'], 'level' => (int)$r['level'], 'reason' => (string)$r['reason'], 'until_at' => (int)$r['until_at']];
    }
    return $out;
}

// «🥇 Лучший трейдер»; в списке — с номером и сроком.
function cm_award_line(array $a, bool $full): string {
    $s = (CM_AWARD_ICONS[$a['level']] ?? '🏅') . ' ' . $a['reason'];
    if (!$full) { return $s; }
    return '#' . $a['id'] . ' ' . $s . ($a['until_at'] > 0 ? cm_t('award_until', ['date' => cm_date($a['until_at'])]) : '');
}

// Для профиля: «🥇 Лучший трейдер, 🏅 Помощь новичкам и ещё 2».
function cm_awards_brief(array $list): string {
    $shown = array_map(function ($a) { return cm_award_line($a, false); }, array_slice($list, 0, CM_AWARDS_BRIEF));
    $more = count($list) - count($shown);
    return implode(', ', $shown) . ($more > 0 ? cm_t('awards_more', ['n' => $more]) : '');
}

// --------------------------------------------------------------------------
//  Команды
// --------------------------------------------------------------------------

// «+награда 5 @ник на 30 дней причина». Срок — только после «на»: иначе
// причина «100 побед» стала бы сроком в 100 минут.
function cm_cmd_award_add(array $ctx, array $cmd): void {
    if (!cm_need2($ctx)) { return; }
    $args = $cmd['args'];
    $t = cm_need_target($ctx, $args);
    if ($t === null) { return; }
    if (!empty($t['is_bot'])) {
        cm_reply($ctx, cm_t('target_bot'));
        return;
    }
    if ((int)$t['id'] === (int)$ctx['actor']) {
        cm_reply($ctx, cm_t('target_self'));
        return;
    }
    if (!preg_match('~^([1-8])(?![0-9])\s*(.*)$~su', trim($args), $m)) {
        cm_reply($ctx, cm_t('award_usage'));
        return;
    }
    $level = (int)$m[1];
    $rest = $m[2];
    $until = 0;
    if (preg_match('~^на\s+~u', cm_norm($rest), $nm)) {
        $after = substr($rest, strlen($nm[0]));
        $term = cm_take_term($after);
        if ($term === -1) {
            cm_reply($ctx, cm_t('term_bad'));
            return;
        }
        if ($term !== null) {
            $rest = $after;
            $until = $term > 0 ? (int)$ctx['now'] + $term : 0;
        }
    }
    $reason = trim($rest);
    if ($reason === '' || mb_strlen($reason) > CM_AWARD_REASON_MAX) {
        cm_reply($ctx, cm_t('award_usage'));
        return;
    }
    $pdo = $ctx['pdo'];
    $chatId = (int)$ctx['chat']['chat_id'];
    if (count(cm_awards_active($pdo, $chatId, (int)$t['id'], (int)$ctx['now'])) >= CM_AWARDS_MAX) {
        cm_reply($ctx, cm_t('award_full', ['name' => $t['name'], 'max' => CM_AWARDS_MAX]));
        return;
    }
    $pdo->prepare('INSERT INTO cm_awards (chat_id, user_id, level, reason, by_id, created_at, until_at, removed_at)
                   VALUES (:c, :u, :l, :r, :b, :n, :until, 0)')
        ->execute([':c' => $chatId, ':u' => (int)$t['id'], ':l' => $level, ':r' => $reason, ':b' => (int)$ctx['actor'],
                   ':n' => (int)$ctx['now'], ':until' => $until]);
    cm_reply($ctx, cm_t('award_done', ['icon' => CM_AWARD_ICONS[$level], 'name' => $t['name'], 'level' => $level, 'reason' => $reason,
        'until' => $until > 0 ? cm_t('award_until', ['date' => cm_date($until)]) : '']), false);
}

function cm_cmd_award_del(array $ctx, array $cmd): void {
    if (!cm_need2($ctx)) { return; }
    $id = preg_match('~^#?([0-9]{1,9})$~', trim($cmd['args']), $m) ? (int)$m[1] : 0;
    $now = (int)$ctx['now'];
    $st = $ctx['pdo']->prepare('UPDATE cm_awards SET removed_at = :n WHERE id = :id AND chat_id = :c
        AND removed_at = 0 AND (until_at = 0 OR until_at > :n2)');
    $st->execute([':n' => $now, ':id' => $id, ':c' => $ctx['chat']['chat_id'], ':n2' => $now]);
    $done = $st->rowCount() > 0;
    cm_reply($ctx, cm_t($done ? 'award_removed' : 'award_missing', ['id' => $id]), !$done);
}

function cm_cmd_awards_show(array $ctx, array $cmd): void {
    if (!cm_need2($ctx)) { return; }
    $args = $cmd['args'];
    $t = cm_target_or_self($ctx, $args);
    if ($t === null) { return; }
    $list = cm_awards_active($ctx['pdo'], (int)$ctx['chat']['chat_id'], (int)$t['id'], (int)$ctx['now']);
    if (!$list) {
        cm_reply($ctx, cm_t('awards_none', ['name' => $t['name']]));
        return;
    }
    $lines = [cm_t('awards_head', ['name' => $t['name']])];
    foreach ($list as $a) { $lines[] = cm_award_line($a, true); }
    cm_reply($ctx, implode("\n", $lines), false);
}
```

- [ ] **Step 4: Команды, раздел, тексты, подключение**

Edit `public_html/api/lib/cm/parse.php` — перед строкой

```php
    'помощь'             => ['help', null, 0, true, false],
```

вставить

```php
    '+награда'           => ['award_add', 'награды', 3, false, true],
    '-награда'           => ['award_del', 'награды', 3, false, false],
    'награды'            => ['awards_show', null, 0, false, false],
```

Edit `public_html/api/lib/cm/parse.php`, в `CM_BARE_ARGS` — фрагмент

```php
    'rep_show'    => '~^$~',
];
```

заменить на

```php
    'rep_show'    => '~^$~',
    'awards_show' => '~^$~',
];
```

Edit `public_html/api/lib/cm/ranks.php`: строку `                     'репутация'];` заменить на `                     'репутация', 'награды'];`.

Edit `public_html/api/lib/cm/texts.php`: после строки

```php
    'rep_top_empty'    => 'Репутации пока ни у кого нет.',
```

добавить

```php
    // награды
    'award_usage'      => 'Так: «+награда 3 @ник причина» или «+награда 5 @ник на 30 дней причина». Степень 1–8, причина — до 100 символов.',
    'award_done'       => '{icon} {name} получает награду {level}-й степени: {reason}{until}',
    'award_until'      => ' (до {date} МСК)',
    'award_full'       => 'У {name} уже {max} наград — больше нельзя.',
    'award_removed'    => 'Награда #{id} снята.',
    'award_missing'    => 'Действующей награды #{id} нет.',
    'awards_head'      => '🏅 Награды {name}:',
    'awards_none'      => 'У {name} наград нет.',
    'awards_more'      => ' и ещё {n}',
```

Edit `public_html/api/lib/cm/cm.php`: `'timers', 'stats', 'rep', 'router', 'cron'] as $cm_part) {` → `'timers', 'stats', 'rep', 'awards', 'router', 'cron'] as $cm_part) {`.

- [ ] **Step 5: Тест проходит, остальное цело**

Run: `/c/xampp/php/php.exe tests/cm_awards_test.php`
Expected: `34 assertions, 0 failures`.

Run:
```bash
for t in tests/cm_*_test.php tests/php74_compat_test.php; do /c/xampp/php/php.exe -d extension=gd "$t" > /dev/null 2>&1 || echo "FAIL $t"; done; echo done
```
Expected: одна строка `done`.

- [ ] **Step 6: Commit**

```bash
git add public_html/api/lib/cm/awards.php tests/cm_awards_test.php public_html/api/lib/cm/parse.php public_html/api/lib/cm/ranks.php public_html/api/lib/cm/texts.php public_html/api/lib/cm/cm.php
git commit -m "feat(cm): награды участникам" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git show --stat HEAD
```
Expected: 6 файлов, без `Bin`.

---

### Task 4: Анкета и профиль

**Files:**
- Create: `public_html/api/lib/cm/profile.php`
- Modify: `public_html/api/lib/cm/parse.php`, `ranks.php`, `texts.php`, `cm.php`, `router.php` (`cm_cmd_whoami`)
- Modify: `tests/cm_router_test.php` (ожидание `кто я`)
- Test: `tests/cm_profile_test.php`

**Interfaces:**
- Consumes: `cm_need2`, `cm_ready2`, `cm_target_or_self`, `cm_day`, `cm_day_text`, `cm_num`, `cm_stats_msgs`, `cm_stats_place`, `cm_stats_first_day` (Task 1); `cm_rep_score` (Task 2); `cm_awards_active`, `cm_awards_brief` (Task 3); этап 1: `cm_rank(array $ctx, int $userId): int`, `CM_RANK_NAMES`, `cm_warns_active(array $ctx, int $userId): int`, `cm_need_target`, `cm_need_level`, `cm_section_default`, `cm_has_link(array $msg, array $domains): bool`, `cm_has_mat(string $text, array $rules): bool`, `chat_filter_compile(array $words)` (`api/lib/chat_filter.php`), `CM_MAT_WORDS`.
- Produces:
  - `cm_section_on(array $chat, string $section): bool`
  - `cm_profile_get(PDO $pdo, int $userId): array` — `['gender' => ''|'m'|'f', 'city' => string, 'birthday' => string, 'about' => string]`
  - `cm_profile_put(PDO $pdo, int $userId, string $field, string $value, int $now): void`
  - `cm_birthday(string $raw, int $now): string` — `'ДД.ММ'` / `'ДД.ММ.ГГГГ'` или `''`
  - `cm_profile_lines(array $ctx, int $userId, string $name): array` — **опора этапа 3** (вставляет строки после «Награды», перед «Варны»)
  - `cm_profile_send(array $ctx, array $t): void`
  - обработчики `cm_cmd_profile`, `cm_cmd_profile_of`, `cm_cmd_profile_set`, `cm_cmd_profile_clear`; `cm_cmd_whoami` (router.php) показывает профиль
  - `CM_GENDERS`, `CM_PROFILE_FIELDS` (фраза → поле), `CM_PROFILE_MAX`
  - Тексты: `profile_gender_usage`, `profile_birthday_usage`, `profile_city_usage`, `profile_about_usage`, `profile_text_bad`, `profile_saved`, `profile_cleared`, `profile_rank`, `profile_msgs`, `profile_place`, `profile_since`, `profile_rep`, `profile_awards`, `profile_warns`, `profile_gender`, `gender_m`, `gender_f`, `profile_city`, `profile_bday`, `profile_about`

- [ ] **Step 1: Падающий тест**

Write `tests/cm_profile_test.php`:

```php
<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';

// Анкета и профиль (этап 2): api/lib/cm/profile.php, «кто я» в router.php.

test('анкета: пол, город, о себе; одна на все чаты', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, 7);
    cm_do($ctx, '+пол Ж');
    assert_eq(cm_t('profile_saved'), cm_last_text($log));
    cm_do($ctx, '+город  Нижний   Новгород ');
    cm_do($ctx, "+о себе Люблю трейды\nи Blox Fruits");
    assert_eq(['gender' => 'f', 'city' => 'Нижний Новгород', 'birthday' => '', 'about' => 'Люблю трейды и Blox Fruits'],
        cm_profile_get($pdo, 7));
    cm_do($ctx, '+пол мужской');
    assert_eq('m', cm_profile_get($pdo, 7)['gender']);
    cm_do($ctx, '+пол кот');
    assert_eq(cm_t('profile_gender_usage'), cm_last_text($log));
    cm_do($ctx, '-город');
    assert_eq(cm_t('profile_cleared'), cm_last_text($log));
    assert_eq('', cm_profile_get($pdo, 7)['city']);
    assert_eq(['gender' => '', 'city' => '', 'birthday' => '', 'about' => ''], cm_profile_get($pdo, 8), 'пустая анкета');
});

test('анкету меняет только хозяин: ответ на чужое сообщение не цель', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $reply = ['reply_to_message' => ['message_id' => 70, 'from' => ['id' => 20, 'first_name' => 'Петя']]];
    cm_do(cm_ctx_for($pdo, $log, 7, '', $reply), '+город Казань');
    assert_eq('Казань', cm_profile_get($pdo, 7)['city']);
    assert_eq('', cm_profile_get($pdo, 20)['city']);
});

test('день рождения: проверка даты, 29.02, год 1900 — нынешний', function () {
    assert_eq('25.12', cm_birthday('25.12', CM_NOW));
    assert_eq('05.01.2005', cm_birthday('5.1.2005', CM_NOW));
    assert_eq('29.02', cm_birthday('29.02', CM_NOW), 'без года 29 февраля можно');
    assert_eq('29.02.2004', cm_birthday('29.02.2004', CM_NOW));
    assert_eq('', cm_birthday('29.02.2005', CM_NOW));
    assert_eq('', cm_birthday('31.04', CM_NOW));
    assert_eq('', cm_birthday('01.01.1899', CM_NOW));
    assert_eq('01.01.2026', cm_birthday('01.01.2026', CM_NOW));
    assert_eq('', cm_birthday('01.01.2027', CM_NOW));
    assert_eq('', cm_birthday('завтра', CM_NOW));
    $pdo = cm_test_pdo();
    $log = [];
    cm_do(cm_ctx_for($pdo, $log, 7), '+др 30.02');
    assert_eq(cm_t('profile_birthday_usage'), cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, 7), '+др 29.02');
    assert_eq('29.02', cm_profile_get($pdo, 7)['birthday']);
});

test('город и о себе: длина, без ссылок и мата', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, 7);
    cm_do($ctx, '+город ' . str_repeat('я', 65));
    assert_eq(cm_t('profile_city_usage'), cm_last_text($log));
    cm_do($ctx, '+о себе ' . str_repeat('я', 201));
    assert_eq(cm_t('profile_about_usage'), cm_last_text($log));
    cm_do($ctx, '+о себе');
    assert_eq(cm_t('profile_about_usage'), cm_last_text($log));
    cm_do($ctx, '+о себе заходи на evil.ru');
    assert_eq(cm_t('profile_text_bad'), cm_last_text($log));
    cm_do($ctx, '+город сука');
    assert_eq(cm_t('profile_text_bad'), cm_last_text($log));
    cm_do($ctx, '+о себе мой сайт maknemy.com');
    assert_eq('мой сайт maknemy.com', cm_profile_get($pdo, 7)['about'], 'maknemy.com можно');
});

test('профиль: все строки по порядку', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 20, 'petya', 'Петя');
    cm_lvl($pdo, 20, 2);
    $ins = $pdo->prepare('INSERT INTO cm_stats (chat_id, user_id, day, msgs) VALUES (:c, :u, :d, :m)');
    $ins->execute([':c' => CM_CHAT, ':u' => 20, ':d' => 20261107, ':m' => 1219]);
    $ins->execute([':c' => CM_CHAT, ':u' => 20, ':d' => 20261108, ':m' => 15]);
    $ins->execute([':c' => CM_CHAT, ':u' => 30, ':d' => 20261108, ':m' => 5000]);
    $ins->execute([':c' => CM_CHAT, ':u' => 31, ':d' => 20261108, ':m' => 4000]);
    $pdo->exec('INSERT INTO cm_rep (chat_id, user_id, score) VALUES (' . CM_CHAT . ', 20, 15)');
    $now = 1794157200;   // 08.11.2026 20:00 МСК
    $o = cm_ctx_for($pdo, $log, CM_OWNER, '', [], [], $now);
    cm_do($o, '+награда 5 @petya Лучший трейдер');
    cm_do($o, '+награда 2 @petya Помощь новичкам');
    cm_do($o, 'варн @petya флуд');
    $me = cm_ctx_for($pdo, $log, 20, '', [], [], $now);
    cm_do($me, '+пол м');
    cm_do($me, '+город Москва');
    cm_do($me, '+др 25.12');
    cm_do($me, '+о себе Трейжу фрукты');
    $queued = (int)$pdo->query('SELECT COUNT(*) FROM cm_queue')->fetchColumn();
    cm_do(cm_ctx_for($pdo, $log, 7, '', [], [], $now), 'профиль @petya');
    assert_eq("👤 Петя\nРанг: 2 — старший модератор\nСообщений: 1 234 (сегодня 15), место 3\nВ чате с: 07.11.2026\n"
        . "Репутация: 15\nНаграды: 🥇 Лучший трейдер, 🏅 Помощь новичкам\nВарны: 1/3\n"
        . "Пол: мужской · Город: Москва · День рождения: 25.12\nО себе: Трейжу фрукты", cm_last_text($log));
    assert_eq($queued, (int)$pdo->query('SELECT COUNT(*) FROM cm_queue')->fetchColumn(), 'профиль постоянный');
});

test('профиль пустого: только ранг, сообщения, репутация, варны', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_do(cm_ctx_for($pdo, $log, 7), 'профиль');
    assert_eq("👤 U7\nРанг: 0 — участник\nСообщений: 0 (сегодня 0)\nРепутация: 0\nВарны: 0/3", cm_last_text($log));
});

test('выключенный раздел прячет свои строки, награды видны всегда', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 20, 'petya', 'Петя');
    $o = cm_ctx_for($pdo, $log, CM_OWNER);
    cm_do($o, '+награда 3 @petya Старожил');
    foreach (['статистика', 'репутация', 'анкета', 'награды'] as $s) { cm_do($o, 'дк ' . $s . ' 6'); }
    cm_do(cm_ctx_for($pdo, $log, 20), '+город Москва');
    $ctx = cm_ctx_for($pdo, $log, 7, '', [], []);
    cm_do($ctx, 'профиль @petya');
    assert_eq("👤 Петя\nРанг: 0 — участник\nНаграды: 🥉 Старожил\nВарны: 0/3", cm_last_text($log));
});

test('кто ты: только с целью; бот — отказ', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $reply = ['reply_to_message' => ['message_id' => 70, 'from' => ['id' => 20, 'first_name' => 'Петя']]];
    cm_do(cm_ctx_for($pdo, $log, 7, '', $reply), 'кто ты');
    assert_eq(0, strpos(cm_last_text($log), "👤 Петя\nРанг: 0"));
    cm_do(cm_ctx_for($pdo, $log, 7), '!кто ты');
    assert_eq(cm_t('need_target'), cm_last_text($log));
    $bot = ['reply_to_message' => ['message_id' => 71, 'from' => ['id' => 555, 'is_bot' => true, 'first_name' => 'Бот']]];
    cm_do(cm_ctx_for($pdo, $log, 7, '', $bot), 'профиль');
    assert_eq(cm_t('target_bot'), cm_last_text($log));
});

test('без префикса: «профиль» и «кто ты» ответом — команды, с лишними словами — речь', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $reply = ['reply_to_message' => ['message_id' => 70, 'from' => ['id' => 20, 'first_name' => 'Петя']]];
    $run = function (string $text, array $extra = []) use ($pdo, &$log): bool {
        return cm_run_command(cm_ctx_for($pdo, $log, 7, $text, $extra), cm_parse($text, CM_BOT));
    };
    assert_eq(true, $run('профиль'));
    assert_eq(true, $run('кто ты', $reply));
    assert_eq(false, $run('кто ты'));
    assert_eq(false, $run('кто ты такой', $reply));
    assert_eq(false, $run('профиль заполни'));
    assert_eq(true, $run('+город Тверь'));
    assert_eq(false, $run('-город срочно'));
});

test('кто я — тот же профиль; без таблиц этапа 2 — как раньше', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_do(cm_ctx_for($pdo, $log, 7), 'кто я');
    assert_eq("👤 U7\nРанг: 0 — участник\nСообщений: 0 (сегодня 0)\nРепутация: 0\nВарны: 0/3", cm_last_text($log));
    $pdo->exec('DROP TABLE cm_stats');
    cm_do(cm_ctx_for($pdo, $log, 7), 'кто я');
    assert_eq('Ваш ранг: 0 — участник. Варны: 0/3.', cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, 7), 'профиль');
    assert_eq(cm_t('not_ready2'), cm_last_text($log));
});

run_tests();
```

- [ ] **Step 2: Убедиться, что тест падает**

Run: `/c/xampp/php/php.exe tests/cm_profile_test.php`
Expected: FAIL — `ERROR - анкета: …: не команда: +пол Ж`, дальше `Call to undefined function cm_birthday()`.

- [ ] **Step 3: Анкета и профиль**

Write `public_html/api/lib/cm/profile.php`:

```php
<?php
// Анкета и профиль участника (этап 2). Анкета (пол, город, день рождения,
// «о себе») — одна на человека для всех чатов, меняет её только хозяин.
// Профиль собирает cm_profile_lines(); «кто я» (router.php) показывает его же.

const CM_GENDERS = ['м' => 'm', 'муж' => 'm', 'мужской' => 'm', 'ж' => 'f', 'жен' => 'f', 'женский' => 'f'];
// Фраза команды → поле анкеты.
const CM_PROFILE_FIELDS = ['+пол' => 'gender', '+город' => 'city', '+др' => 'birthday', '+о себе' => 'about',
                           '-пол' => 'gender', '-город' => 'city', '-др' => 'birthday', '-о себе' => 'about'];
const CM_PROFILE_MAX = ['city' => 64, 'about' => 200];

// Включён ли раздел «дк» в чате (уровень меньше 6).
function cm_section_on(array $chat, string $section): bool {
    return cm_need_level($chat, $section, cm_section_default($section)) < 6;
}

// Анкета человека; нет строки — все поля пустые.
function cm_profile_get(PDO $pdo, int $userId): array {
    $out = ['gender' => '', 'city' => '', 'birthday' => '', 'about' => ''];
    $st = $pdo->prepare('SELECT gender, city, birthday, about FROM cm_profiles WHERE user_id = :u');
    $st->execute([':u' => $userId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r) {
        foreach ($out as $k => $v) { $out[$k] = (string)$r[$k]; }
    }
    return $out;
}

function cm_profile_put(PDO $pdo, int $userId, string $field, string $value, int $now): void {
    if (!in_array($field, ['gender', 'city', 'birthday', 'about'], true)) { return; }
    $p = [':u' => $userId, ':v' => $value, ':n' => $now];
    $up = $pdo->prepare("UPDATE cm_profiles SET $field = :v, updated_at = :n WHERE user_id = :u");
    $up->execute($p);
    if ($up->rowCount() > 0) { return; }
    try {
        $pdo->prepare("INSERT INTO cm_profiles (user_id, $field, updated_at) VALUES (:u, :v, :n)")->execute($p);
    } catch (PDOException $e) {
        $up->execute($p);   // строка уже есть, а MySQL не посчитал неизменённую
    }
}

// «25.12» или «25.12.2005» → «25.12» / «25.12.2005»; '' — не дата. Год — от
// 1900 до нынешнего по Москве. Без года 29.02 проверяем по високосному 2000-му.
function cm_birthday(string $raw, int $now): string {
    if (!preg_match('~^([0-9]{1,2})\.([0-9]{1,2})(?:\.([0-9]{4}))?$~', trim($raw), $m)) { return ''; }
    $day = (int)$m[1];
    $month = (int)$m[2];
    $hasYear = isset($m[3]) && $m[3] !== '';
    $year = $hasYear ? (int)$m[3] : 2000;
    if ($hasYear && ($year < 1900 || $year > intdiv(cm_day($now), 10000))) { return ''; }
    if (!checkdate($month, $day, $year)) { return ''; }
    return sprintf('%02d.%02d', $day, $month) . ($hasYear ? '.' . $year : '');
}

// --------------------------------------------------------------------------
//  Профиль
// --------------------------------------------------------------------------

/**
 * Профиль строками: имя, ранг, сообщения, «в чате с», репутация, награды,
 * варны, анкета. Пустые строки анкеты не выводятся, строки выключенного
 * раздела — тоже; награды видны всегда, как и команда «награды». Этап 3
 * вставляет «Пара», «Брак», «Клан», «Дуэли» после наград, перед варнами.
 */
function cm_profile_lines(array $ctx, int $userId, string $name): array {
    $pdo = $ctx['pdo'];
    $chat = $ctx['chat'];
    $chatId = (int)$chat['chat_id'];
    $now = (int)$ctx['now'];
    $rank = cm_rank($ctx, $userId);
    $lines = ['👤 ' . $name, cm_t('profile_rank', ['n' => $rank, 'rank' => CM_RANK_NAMES[$rank]])];
    if (cm_section_on($chat, 'статистика')) {
        $place = cm_stats_place($pdo, $chatId, $userId, 0, $now);
        $lines[] = cm_t('profile_msgs', ['all' => cm_num(cm_stats_msgs($pdo, $chatId, $userId, 0, $now)),
            'today' => cm_num(cm_stats_msgs($pdo, $chatId, $userId, 1, $now)),
            'place' => $place > 0 ? cm_t('profile_place', ['n' => $place]) : '']);
        $first = cm_stats_first_day($pdo, $chatId, $userId);
        if ($first > 0) { $lines[] = cm_t('profile_since', ['date' => cm_day_text($first)]); }
    }
    if (cm_section_on($chat, 'репутация')) {
        $lines[] = cm_t('profile_rep', ['score' => cm_rep_score($pdo, $chatId, $userId)]);
    }
    $awards = cm_awards_active($pdo, $chatId, $userId, $now);
    if ($awards) { $lines[] = cm_t('profile_awards', ['list' => cm_awards_brief($awards)]); }
    $lines[] = cm_t('profile_warns', ['n' => cm_warns_active($ctx, $userId), 'limit' => (int)$chat['settings']['warns']['limit']]);
    if (cm_section_on($chat, 'анкета')) {
        $p = cm_profile_get($pdo, $userId);
        $parts = [];
        if ($p['gender'] !== '') { $parts[] = cm_t('profile_gender', ['g' => cm_t($p['gender'] === 'f' ? 'gender_f' : 'gender_m')]); }
        if ($p['city'] !== '') { $parts[] = cm_t('profile_city', ['city' => $p['city']]); }
        if ($p['birthday'] !== '') { $parts[] = cm_t('profile_bday', ['date' => $p['birthday']]); }
        if ($parts) { $lines[] = implode(' · ', $parts); }
        if ($p['about'] !== '') { $lines[] = cm_t('profile_about', ['about' => $p['about']]); }
    }
    return $lines;
}

function cm_profile_send(array $ctx, array $t): void {
    if (!empty($t['is_bot'])) {
        cm_reply($ctx, cm_t('target_bot'));
        return;
    }
    cm_reply($ctx, implode("\n", cm_profile_lines($ctx, (int)$t['id'], (string)$t['name'])), false);
}

// --------------------------------------------------------------------------
//  Команды
// --------------------------------------------------------------------------

function cm_cmd_profile(array $ctx, array $cmd): void {
    if (!cm_need2($ctx)) { return; }
    $args = $cmd['args'];
    $t = cm_target_or_self($ctx, $args);
    if ($t !== null) { cm_profile_send($ctx, $t); }
}

function cm_cmd_profile_of(array $ctx, array $cmd): void {
    if (!cm_need2($ctx)) { return; }
    $args = $cmd['args'];
    $t = cm_need_target($ctx, $args);
    if ($t !== null) { cm_profile_send($ctx, $t); }
}

// «+пол м», «+город Москва», «+др 25.12», «+о себе текст». Цель не
// принимается: ответ на чужое сообщение меняет свою анкету. Город и «о себе»
// — одной строкой, без ссылок и мата: анкету показывают во всех чатах, а
// сообщение-команду фильтры уже не проверяют.
function cm_cmd_profile_set(array $ctx, array $cmd): void {
    if (!cm_need2($ctx)) { return; }
    $field = CM_PROFILE_FIELDS[$cmd['phrase']];
    $raw = trim($cmd['args'] . ' ' . $cmd['body']);
    if ($field === 'gender') {
        $value = CM_GENDERS[cm_norm($raw)] ?? '';
    } elseif ($field === 'birthday') {
        $value = cm_birthday($raw, (int)$ctx['now']);
    } else {
        $value = trim((string)preg_replace('~\s+~u', ' ', $raw));
        if (mb_strlen($value) > CM_PROFILE_MAX[$field]) { $value = ''; }
    }
    if ($value === '') {
        cm_reply($ctx, cm_t('profile_' . $field . '_usage'));
        return;
    }
    if (($field === 'city' || $field === 'about')
        && (cm_has_link(['text' => $value], []) || cm_has_mat($value, chat_filter_compile(CM_MAT_WORDS)))) {
        cm_reply($ctx, cm_t('profile_text_bad'));
        return;
    }
    cm_profile_put($ctx['pdo'], (int)$ctx['actor'], $field, $value, (int)$ctx['now']);
    cm_reply($ctx, cm_t('profile_saved'));
}

function cm_cmd_profile_clear(array $ctx, array $cmd): void {
    if (!cm_need2($ctx)) { return; }
    cm_profile_put($ctx['pdo'], (int)$ctx['actor'], CM_PROFILE_FIELDS[$cmd['phrase']], '', (int)$ctx['now']);
    cm_reply($ctx, cm_t('profile_cleared'));
}
```

- [ ] **Step 4: Команды, раздел, тексты, подключение, «кто я»**

Edit `public_html/api/lib/cm/parse.php` — перед строкой

```php
    'помощь'             => ['help', null, 0, true, false],
```

вставить

```php
    'профиль'            => ['profile', null, 0, false, false],
    'кто ты'             => ['profile_of', null, 0, false, true],
    '+пол'               => ['profile_set', 'анкета', 0, false, false],
    '+город'             => ['profile_set', 'анкета', 0, false, false],
    '+др'                => ['profile_set', 'анкета', 0, false, false],
    '+о себе'            => ['profile_set', 'анкета', 0, false, false],
    '-пол'               => ['profile_clear', 'анкета', 0, true, false],
    '-город'             => ['profile_clear', 'анкета', 0, true, false],
    '-др'                => ['profile_clear', 'анкета', 0, true, false],
    '-о себе'            => ['profile_clear', 'анкета', 0, true, false],
```

Строка `'кто я' => ['whoami', null, 0, true, false]` остаётся как есть: без цели, только свой профиль.

Edit `public_html/api/lib/cm/parse.php`, в `CM_BARE_ARGS` — фрагмент

```php
    'awards_show' => '~^$~',
];
```

заменить на

```php
    'awards_show' => '~^$~',
    'profile'     => '~^$~',
    'profile_of'  => '~^$~',
];
```

Edit `public_html/api/lib/cm/ranks.php`: `                     'репутация', 'награды'];` → `                     'репутация', 'награды', 'анкета'];`.

Edit `public_html/api/lib/cm/texts.php`: после строки

```php
    'awards_more'      => ' и ещё {n}',
```

добавить

```php
    // анкета и профиль
    'profile_gender_usage'   => 'Так: «+пол м» или «+пол ж».',
    'profile_birthday_usage' => 'Так: «+др 25.12» или «+др 25.12.2005» (год — от 1900 до нынешнего).',
    'profile_city_usage'     => 'Так: «+город Москва» — до 64 символов.',
    'profile_about_usage'    => 'Так: «+о себе текст» — до 200 символов.',
    'profile_text_bad'       => 'Без ссылок и мата: анкету видно во всех чатах.',
    'profile_saved'          => 'Анкета сохранена.',
    'profile_cleared'        => 'Убрано из анкеты.',
    'profile_rank'     => 'Ранг: {n} — {rank}',
    'profile_msgs'     => 'Сообщений: {all} (сегодня {today}){place}',
    'profile_place'    => ', место {n}',
    'profile_since'    => 'В чате с: {date}',
    'profile_rep'      => 'Репутация: {score}',
    'profile_awards'   => 'Награды: {list}',
    'profile_warns'    => 'Варны: {n}/{limit}',
    'profile_gender'   => 'Пол: {g}',
    'gender_m'         => 'мужской',
    'gender_f'         => 'женский',
    'profile_city'     => 'Город: {city}',
    'profile_bday'     => 'День рождения: {date}',
    'profile_about'    => 'О себе: {about}',
```

Edit `public_html/api/lib/cm/cm.php`: строку

```php
          'welcome', 'reports', 'cleanup', 'notes', 'timers', 'stats', 'rep', 'awards', 'router', 'cron'] as $cm_part) {
```

заменить на

```php
          'welcome', 'reports', 'cleanup', 'notes', 'timers', 'stats', 'rep', 'awards', 'profile',
          'router', 'cron'] as $cm_part) {
```

Edit `public_html/api/lib/cm/router.php` — фрагмент

```php
function cm_cmd_whoami(array $ctx, array $cmd): void {
    $r = (int)$ctx['rank'];
```

заменить на

```php
// «кто я» — свой профиль (profile.php). Пока нет таблиц этапа 2 — ранг и
// варны, как в этапе 1.
function cm_cmd_whoami(array $ctx, array $cmd): void {
    if (cm_ready2($ctx['pdo'])) {
        $from = is_array($ctx['msg']['from'] ?? null) ? $ctx['msg']['from'] : [];
        cm_reply($ctx, implode("\n", cm_profile_lines($ctx, (int)$ctx['actor'], cm_display_name($from))), false);
        return;
    }
    $r = (int)$ctx['rank'];
```

Edit `tests/cm_router_test.php`, тест «помощь, кто я, id»: строку

```php
    assert_eq('Ваш ранг: 0 — участник. Варны: 0/3.', cm_last_text($log));
```

заменить на

```php
    assert_eq(0, strpos(cm_last_text($log), "👤 U7\nРанг: 0 — участник\n"), 'кто я — профиль');
```

(полный текст профиля через роутер проверит Task 5: там в нём появится счёт сообщений).

- [ ] **Step 5: Тесты проходят, остальное цело**

Run: `/c/xampp/php/php.exe tests/cm_profile_test.php`
Expected: `44 assertions, 0 failures`.

Run: `/c/xampp/php/php.exe tests/cm_router_test.php`
Expected: `52 assertions, 0 failures`.

Run:
```bash
for t in tests/cm_*_test.php tests/php74_compat_test.php; do /c/xampp/php/php.exe -d extension=gd "$t" > /dev/null 2>&1 || echo "FAIL $t"; done; echo done
```
Expected: одна строка `done`.

- [ ] **Step 6: Commit**

```bash
git add public_html/api/lib/cm/profile.php tests/cm_profile_test.php tests/cm_router_test.php public_html/api/lib/cm/parse.php public_html/api/lib/cm/ranks.php public_html/api/lib/cm/texts.php public_html/api/lib/cm/cm.php public_html/api/lib/cm/router.php
git commit -m "feat(cm): анкета и профиль, «кто я» показывает профиль" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git show --stat HEAD
```
Expected: 8 файлов, без `Bin`.

---

### Task 5: Счётчик и голоса в потоке сообщений, помощь

**Files:**
- Modify: `public_html/api/lib/cm/router.php` (`cm_on_message`)
- Modify: `public_html/api/lib/cm/texts.php` (`help`)
- Test: `tests/cm_router_test.php` (новые тесты)

**Interfaces:**
- Consumes: `cm_stats_hit`, `cm_stats_msgs` (Task 1), `cm_rep_try`, `cm_rep_score` (Task 2), `cm_profile_lines` через `cm_cmd_whoami` (Task 4). В `cm_on_message` к этому месту уже есть `$pdo`, `$chatId`, `$uid`, `$now`, `$edited`, а `$ctx['rank']` посчитан.
- Produces: порядок `cm_on_message` — автор → спамер → **`cm_stats_hit`** (не для правок) → **`cm_rep_try`** (не для правок; `true` — выход) → команда → фильтры → `#заметка`. Этап 3 сюда не добавляет ничего (у него — кнопки и cron).

- [ ] **Step 1: Падающие тесты**

Edit `tests/cm_router_test.php` — перед строкой

```php
test('вебхук: правки сообщений и право удалять в группах', function () {
```

вставить

```php
test('статистика: сообщение и команда считаются, правка и спамер — нет', function () {
    $pdo = cm_test_pdo();
    $log = [];
    rt_run($pdo, cm_gmsg(7, 'привет'), $log);
    rt_run($pdo, cm_gmsg(7, 'помощь'), $log);
    rt_run($pdo, cm_gmsg(7, 'привет!'), $log, [], CM_NOW, 'edited_message');
    $pdo->exec("INSERT INTO cm_spam (user_id, reason, added_by, added_at) VALUES (66, 'скам', '900', 0)");
    rt_run($pdo, cm_gmsg(66, 'купи'), $log);
    assert_eq(2, cm_stats_msgs($pdo, CM_CHAT, 7, 0, CM_NOW));
    assert_eq(0, cm_stats_msgs($pdo, CM_CHAT, 66, 0, CM_NOW));
    assert_eq(true, strpos(cm_t('help'), 'стата') !== false, 'помощь знает этап 2');
});

test('ответ «спасибо» — голос: раньше команд и фильтров', function () {
    $pdo = cm_test_pdo();
    $log = [];
    rt_run($pdo, cm_gmsg(CM_OWNER, 'фильтр мат вкл'), $log);
    rt_run($pdo, cm_gmsg(CM_OWNER, '+слово спасибо'), $log);
    $reply = ['reply_to_message' => ['message_id' => 70, 'from' => ['id' => 20, 'first_name' => 'Петя']]];
    rt_run($pdo, cm_gmsg(7, 'спасибо', $reply), $log);
    assert_eq(0, count(cm_calls($log, 'deleteMessage')), 'до фильтра мата не дошло');
    assert_eq('👍 U7 → Петя: +1 (репутация 1)', cm_last_text($log));
    assert_eq(1, cm_stats_msgs($pdo, CM_CHAT, 7, 0, CM_NOW), 'голос — тоже сообщение');
    rt_run($pdo, cm_gmsg(8, 'спасибо', $reply), $log, [], CM_NOW, 'edited_message');
    assert_eq(1, cm_rep_score($pdo, CM_CHAT, 20), 'правка — не голос');
    assert_eq(1, count(cm_calls($log, 'deleteMessage')), 'правку видят фильтры');
    rt_run($pdo, cm_gmsg(7, 'спасибо огромное', $reply), $log);
    assert_eq(2, count(cm_calls($log, 'deleteMessage')), 'не голос — фильтры как обычно');
});

test('кто я — профиль, и само сообщение уже посчитано', function () {
    $pdo = cm_test_pdo();
    $log = [];
    rt_run($pdo, cm_gmsg(7, 'кто я'), $log);
    assert_eq("👤 U7\nРанг: 0 — участник\nСообщений: 1 (сегодня 1), место 1\nВ чате с: 21.09.2026\nРепутация: 0\nВарны: 0/3",
        cm_last_text($log));
});

test('без таблиц этапа 2: модерация как раньше', function () {
    $pdo = cm_test_pdo();
    $pdo->exec('DROP TABLE cm_stats');
    $log = [];
    rt_run($pdo, cm_gmsg(7, 'зайди evil.ru'), $log);
    assert_eq(1, count(cm_calls($log, 'deleteMessage')));
    rt_run($pdo, cm_gmsg(7, '+', ['reply_to_message' => ['message_id' => 70, 'from' => ['id' => 20, 'first_name' => 'Петя']]]), $log);
    rt_run($pdo, cm_gmsg(7, 'кто я'), $log);
    assert_eq(['Ваш ранг: 0 — участник. Варны: 0/3.'], cm_texts($log));
});

```

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `/c/xampp/php/php.exe tests/cm_router_test.php`
Expected: FAIL — `ASSERT FAIL` в новых тестах (статистика `0` вместо `2`, «до фильтра мата не дошло», профиль без «Сообщений: 1»), в конце `64 assertions, 9 failures`.

- [ ] **Step 3: Роутер**

Edit `public_html/api/lib/cm/router.php`, комментарий над `cm_on_message` — фрагмент

```php
 *  2. спамер из базы — бан;
 *  3. команда — выполнить;
 *  4. фильтры по старшинству;
 *  5. «#имя» — заметка.
 * Правку проверяют только фильтры: команду из правки не выполняем.
```

заменить на

```php
 *  2. спамер из базы — бан;
 *  3. +1 сообщение в статистику (этап 2, stats.php);
 *  4. ответ «+» / «спасибо» — голос репутации (этап 2, rep.php);
 *  5. команда — выполнить;
 *  6. фильтры по старшинству;
 *  7. «#имя» — заметка.
 * Правку проверяют только фильтры: команду и голос из правки не выполняем,
 * в статистику правка не идёт.
```

Edit `public_html/api/lib/cm/router.php`, в `cm_on_message` — фрагмент

```php
    if (!$edited && isset($msg['text'])) {
        $cmd = cm_parse((string)$msg['text'], $ctx['bot']);
```

заменить на

```php
    if (!$edited) { cm_stats_hit($pdo, $chatId, $uid, $now); }
    if (!$edited && isset($msg['text'])) {
        if (cm_rep_try($ctx)) { return; }
        $cmd = cm_parse((string)$msg['text'], $ctx['bot']);
```

Этот фрагмент стоит сразу после блока спамера (`cm_spam_has` … `return;`): спамера не считаем.

- [ ] **Step 4: Помощь**

Edit `public_html/api/lib/cm/texts.php`, текст `help`: строку

```php
        . "🔑 Доступ: дк, дк бан 2",
```

заменить на

```php
        . "🔑 Доступ: дк, дк бан 2\n"
        . "📊 Статистика: стата, стата неделя, стата 20 месяц, моя стата\n"
        . "👤 Профиль: профиль, кто я, кто ты (ответом), +пол м, +город, +др 25.12, +о себе, -город\n"
        . "⭐ Репутация: + или спасибо (ответом), +реп, -реп, репа, топ репы\n"
        . "🏅 Награды: награды, +награда 3 @ник причина, +награда 5 @ник на 30 дней причина, -награда 12",
```

- [ ] **Step 5: Тесты проходят**

Run: `/c/xampp/php/php.exe tests/cm_router_test.php`
Expected: `64 assertions, 0 failures`.

Run:
```bash
for t in tests/cm_*_test.php tests/php74_compat_test.php tests/telegram_test.php; do /c/xampp/php/php.exe -d extension=gd "$t" > /dev/null 2>&1 || echo "FAIL $t"; done; echo done
```
Expected: одна строка `done`.

- [ ] **Step 6: Commit**

```bash
git add public_html/api/lib/cm/router.php public_html/api/lib/cm/texts.php tests/cm_router_test.php
git commit -m "feat(cm): счётчик сообщений и голоса в потоке группы" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git show --stat HEAD
```
Expected: 3 файла, без `Bin`.

---

### Task 6: Описание, полный прогон, PR

**Files:**
- Modify: `docs/chat-manager.md` (переписать целиком)

- [ ] **Step 1: Описание**

Write `docs/chat-manager.md` (сначала Read: если этап 1 в нём что-то поменял после `c60399d`, перенести это в новый текст):

````markdown
# Чат-менеджер (этапы 1–2: модерация и активность)

Наш бот модерирует любые группы, куда его добавят: ранги, наказания,
фильтры с триггерами, приветствие и правила, репорты, общая база спамеров,
чистка, заметки, таймеры. С этапа 2 — ещё статистика сообщений, анкета и
профиль, репутация и награды. Только русский язык. Спеки —
`docs/superpowers/specs/2026-11-07-chat-manager-moderation-design.md` и
`docs/superpowers/specs/2026-11-08-chat-manager-activity-design.md`.

Сам, без команды модератора, бот делает две вещи: удаляет ссылки (кроме
maknemy.com и доменов чата) и банит спамеров из общей базы. Всё остальное
включают модераторы командами (`помощь` в группе). Счётчик сообщений идёт
молча и ничего не отправляет.

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

## Этап 2: запуск

1. Мерж PR этапа 2 (после PR этапа 1) → деплой.
2. phpMyAdmin: выполнить `docs/migrations/2026-11-08-chat-manager-activity.sql` —
   строго после миграции этапа 1. Пока её нет, команды этапа 2 отвечают
   «Эта часть бота ещё не настроена.», а модерация работает как раньше.
3. Больше ничего: вебхук, privacy mode и cron — из этапа 1.
4. Проверка в тестовой группе: пара сообщений, `стата`, ответ `+` на чужое
   сообщение, `+город Москва`, `профиль`, `+награда 3 @ник тест` от создателя,
   `награды @ник`.

Статистика считается с момента, когда бот появился в чате (прошлую переписку
Telegram боту не отдаёт) и после миграции этапа 2.

## Разделы этапа 2

| Раздел для `дк` | Команды | Ранг |
| --- | --- | --- |
| `статистика` | `стата [мест 1–50] [день\|неделя\|месяц\|всё]`, `моя стата` | 0 |
| `репутация` | ответ `+` / `-` / `спасибо` / `спс` / `благодарю`, `+реп`, `-реп`, `репа`, `топ репы` | 0 |
| `награды` | `+награда 5 @ник [на 30 дней] причина`, `-награда 12` | 3 |
| `анкета` | `+пол м\|ж`, `+город`, `+др 25.12[.2005]`, `+о себе`, `-пол`, `-город`, `-др`, `-о себе` | 0 |

Вне разделов, видны всегда: `профиль`, `кто я`, `кто ты` (ответом или
`@ник`), `награды`. `дк <раздел> 6` выключает раздел, и его строки пропадают
из профиля; награды в профиле видны всегда. `дк награды 2` — выдавать и
снимать награды можно с ранга 2.

Правила этапа 2:

- Без префикса `стата`, `моя стата`, `репа`, `награды`, `профиль`, `кто ты`
  срабатывают, только если после команды нет ничего, кроме цели (у `стата` —
  числа и периода): «стата норм» — обычная речь (`CM_BARE_ARGS`, parse.php).
- Голос — ответ на чужое сообщение, первая строка ровно `+`, `-`, `спасибо`,
  `спс` или `благодарю`. Одному человеку — раз в 24 часа, всего — 20 голосов
  за 24 часа. Лишний голос ответом — молча, командой `+реп` — с пояснением.
- Анкета одна на все чаты; город и «о себе» — без ссылок и мата.

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
- Этап 2: `stats` (счётчик `cm_stats` по дням МСК, топы, `cm_ready2`),
  `rep`, `awards`, `profile` (`cm_profile_lines` — текст профиля и «кто я»).
  Роутер: после проверки спамера — `cm_stats_hit`, перед командами —
  `cm_rep_try`.
- `api/cm_cron.php` — раз в минуту: таймеры, автоудаление, чистка `cm_msgs`
  старше 48 часов.
- Ограничения Telegram: список участников боту не отдают — массовые кики
  только по тем, кого бот видел; сообщения старше 48 часов бот удалить не
  может; мут работает только в супергруппе.

## Дальше

Этап 3 — игры и отношения (`docs/superpowers/specs/2026-11-08-chat-manager-games-design.md`).
Строится на тех же таблицах `cm_*` и модуле.
````

- [ ] **Step 2: Полный прогон**

Run:
```bash
for t in tests/*_test.php tests/harness_selfcheck.php; do /c/xampp/php/php.exe -d extension=gd "$t" > /dev/null 2>&1 || echo "FAIL $t"; done; echo done
```
Expected: `done` и не больше двух строк `FAIL`, обе известные и не связанные с веткой (есть и на чистом `master`):
- `FAIL tests/chat_page_test.php` — три проверки ищут в JS `\n`, а Windows-checkout с `core.autocrlf=true` даёт CRLF;
- `FAIL tests/chat_test.php` — `ALTER TABLE … DROP COLUMN`, которого нет у SQLite 3.33 в XAMPP.

Любая другая `FAIL` — запустить тест отдельно и чинить. JS-тесты (`*_test.mjs`) этот этап не трогает.

- [ ] **Step 3: Проверка мусорных байтов**

Run:
```bash
git fetch maknemy
git diff --stat maknemy/master...HEAD | grep -c Bin
git diff maknemy/master...HEAD -U0 | LC_ALL=C grep -c $'\xc2\xa0\|\xe2\x80\x8b\|\xef\xbb\xbf'
```
Expected: `0` и `0` (NBSP, ZWSP, BOM). `grep -cP '\x{00A0}…'` в Git Bash падает — поэтому байтовый шаблон.

- [ ] **Step 4: Commit**

```bash
git add docs/chat-manager.md
git commit -m "docs(cm): чат-менеджер, этап 2 — запуск и устройство" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git show --stat HEAD
```

- [ ] **Step 5: Push и PR**

`master` защищён: только ветка и PR, мержит владелец. Remote — `maknemy` (`origin` мёртв). Если `git push` висит ~21 с при живом `curl` — недоступен IP GitHub: `git -c http.sslbackend=schannel -c http.curloptResolve=github.com:443:<живой IP> push …`.

Сначала узнать, смержен ли этап 1:

```bash
gh pr view 222 --repo kannurali/MaknemyTierlist --json state,mergedAt
```

- `MERGED` → `git merge maknemy/master` в ветку (конфликт — разобрать по смыслу, не затирая чужие правки master), снова Step 2 и Step 3. PR — в `master`, в нём только этап 2.
- `OPEN` → PR всё равно в `master`: в диффе будут и коммиты этапа 1, после мержа #222 они сами уйдут из диффа. В описании — «мержить после #222».

Write `<скратчпад>/pr-body.md` (вне репозитория):

```markdown
Этап 2 из трёх чат-менеджера — активность: статистика сообщений, анкета и профиль, репутация, награды. Основа — этап 1 (#222): **мержить после него**.

**Бот сам ничего не отправляет:** считает сообщения молча, всё остальное — по командам. Любой раздел модератор выключает: `дк статистика 6`, `дк репутация 6`, `дк награды 6`, `дк анкета 6`.

**Команды** (префикс `! . /` не обязателен; цель — ответом или @ником):
- статистика: `стата`, `стата неделя`, `стата 20 месяц`, `стата всё`, `моя стата`
- профиль: `профиль`, `кто я`, `кто ты`; анкета (одна на все чаты): `+пол м`, `+город Москва`, `+др 25.12`, `+о себе …`, `-город`
- репутация: ответ `+` / `-` / `спасибо` на чужое сообщение, `+реп`, `-реп`, `репа`, `топ репы`; одному человеку — раз в сутки, всего — 20 голосов в сутки
- награды (с ранга 3, `дк награды 2` — с ранга 2): `+награда 5 @ник Лучший трейдер`, `+награда 3 @ник на 30 дней …`, `-награда 12`, `награды`

Без префикса `стата норм` или `награды раздали` — обычная речь, бот не отвечает.

Устройство и запуск — `docs/chat-manager.md`, спека — `docs/superpowers/specs/2026-11-08-chat-manager-activity-design.md`, план — `docs/superpowers/plans/2026-11-08-chat-manager-activity.md`.

**После мержа:**
1. phpMyAdmin: `docs/migrations/2026-11-08-chat-manager-activity.sql` — после миграции этапа 1. Пока её нет, команды этапа 2 отвечают «ещё не настроено», модерация работает.
2. Тестовая группа: пара сообщений, `стата`, ответ `+`, `+город Москва`, `профиль`, `+награда 3 @ник тест`.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
```

```bash
git push -u maknemy feat/chat-manager-activity
gh pr create --repo kannurali/MaknemyTierlist --base master --head feat/chat-manager-activity --title "Чат-менеджер, этап 2: активность" --body-file "<скратчпад>/pr-body.md"
```

`<скратчпад>` — папка скратчпада сессии исполнителя (абсолютный путь). Если #222 уже смержен, строку «мержить после него» из описания убрать.
