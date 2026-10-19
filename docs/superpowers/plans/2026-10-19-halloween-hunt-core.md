# «Тыквенная охота», PR A — данные, логика, API

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** серверная часть главы 1 ивента: таблицы `hunt_*`, логика наград,
билетов и итогов в `api/lib/hunt.php`, публичные и личные эндпоинты, поле
`hunt` в `api/session.php`, тесты. Интерфейса нет — это PR B (админка) и PR C
(страница, индикатор, секретки).

**Architecture:** журнал `hunt_ledger` с `UNIQUE (hunt_id, user_id, ref)` —
единственный источник правды о наградах; `hunt_wallets` — кеш журнала,
обновляется в той же транзакции. Списание — условный `UPDATE … WHERE balance
>= :p AND tickets = :have`. Эндпоинты по образцу розыгрышей:
`handle_*()` с `[код, тело]`, обвязка под `if (!defined('TESTING'))`.

**Tech Stack:** PHP 7.4 (без `match`, `str_contains`, именованных
аргументов), PDO MySQL на бою, SQLite в тестах и на стенде, тесты
`tests/lib.php`.

Спека: `docs/superpowers/specs/2026-10-19-halloween-hunt-design.md`.

## Global Constraints

- PHP 7.4: `tests/php74_compat_test.php` разбирает все файлы.
- Повтор ловится SQLSTATE `23000` (одинаков у MySQL и SQLite), без
  `INSERT IGNORE` / `ON DUPLICATE KEY` / `FOR UPDATE`.
- В MySQL у `TEXT` не бывает `DEFAULT` — такие колонки `NULL`.
- День ежедневных заданий — дата по `Europe/Moscow`.
- Правильный ответ вопроса и `explain` не покидают сервер до верного ответа.
- `public_html/js|css` без пояснительных комментариев (PHP — можно).
- Билет: по умолчанию цена 50, лимит 3; один человек — один приз в итогах.
- Публичный ответ кешируется LiteSpeed 30 с с тегом `hunt`; личное —
  `no-store`; хвосты запроса, которых сайт не шлёт, `.htaccess` отбивает 403.

## Файлы

| Файл | Что |
| --- | --- |
| `docs/migrations/2026-10-20-hunt.sql` | создать: 9 таблиц `hunt_*` |
| `schema.sql` | дописать те же таблицы, байт в байт |
| `public_html/api/lib/hunt.php` | создать: вся логика |
| `public_html/api/hunt.php` | создать: GET публичное (`?page=`) |
| `public_html/api/hunt_me.php` | создать: GET личное |
| `public_html/api/hunt_act.php` | создать: POST действия |
| `public_html/api/hunt_stat.php` | создать: POST счётчики гостей |
| `public_html/api/session.php` | поле `hunt` |
| `public_html/.htaccess` | `hunt.php` принимает только пусто или `page=<ключ>` |
| `tests/hunt_schema.php` | создать: SQLite-таблицы для тестов и стенда |
| `tests/hunt_test.php` | создать |
| `tests/dos_guard_test.php` | новые эндпоинты в списках кеша |
| `tools/seed-hunt.php` | создать: демо-сезон на стенде для PR C |

## Интерфейсы `api/lib/hunt.php`

```php
const HUNT_TZ = 'Europe/Moscow';
const HUNT_KINDS = ['quiz', 'tg', 'link', 'visit'];
const HUNT_LEVELS = ['intro', 'easy', 'medium', 'hard'];
const HUNT_PERIODS = ['once', 'daily'];
const HUNT_LANGS = ['all', 'ru', 'en'];
const HUNT_SECRET_LEVELS = ['easy', 'hint', 'riddle'];
const HUNT_SPRITES = ['pumpkin', 'ghost', 'candle', 'bat', 'skull'];
const HUNT_WIN_STATUSES = ['won', 'confirmed', 'queued', 'given', 'expired'];
const HUNT_METRICS = ['page_v', 'invite_v', 'invite_c', 'bar_c'];
const HUNT_SPOTS = [/* ключ страницы => [место => подпись для админки] */];

function hunt_ready(PDO $pdo): bool;
function hunt_day(int $now): string;                       // 'Y-m-d' по Москве
function hunt_load(PDO $pdo, int $id): ?array;
function hunt_current(PDO $pdo, int $now): ?array;         // live или drawn, starts_at <= now
function hunt_phase(array $hunt, int $now): string;        // draft|soon|open|closed|drawn
function hunt_chapters(PDO $pdo, int $huntId): array;
function hunt_open_chapters(array $chapters, int $now): array; // [n, …]

function hunt_wallet(PDO $pdo, int $huntId, string $uid): array; // balance, earned, tickets, src, days, last_day
function hunt_award(PDO $pdo, int $huntId, string $uid, string $kind, string $ref, int $delta, int $now, string $src = ''): array;
// → ['status' => 'done'|'already', 'wallet' => […]]

function hunt_tasks(PDO $pdo, int $huntId, bool $activeOnly = true): array;
function hunt_task_ref(array $task, int $now): string;
function hunt_task_public(array $task, array $channels): array;
function hunt_do_task(PDO $pdo, array $cfg, callable $send, string $uid, int $taskId, array $in, int $now): array;
// → [код, ['ok', 'status', 'wallet'?, 'explain'?, 'hint'?]]

function hunt_secrets(PDO $pdo, int $huntId, bool $activeOnly = true): array;
function hunt_secret_ref(array $secret, int $now): string;
function hunt_find_secret(PDO $pdo, array $cfg, string $uid, int $id, string $page, int $now, string $src = ''): array;

function hunt_ticket_no(int $id): string;                  // '#0042'
function hunt_buy_ticket(PDO $pdo, array $cfg, string $uid, int $seq, int $now): array;

function hunt_draw(PDO $pdo, array $cfg, int $huntId, int $now, ?callable $rand = null): array;
function hunt_expire_wins(PDO $pdo, int $huntId, int $now): int;
function hunt_redraw(PDO $pdo, array $cfg, int $winId, int $now): array;
function hunt_confirm_win(PDO $pdo, string $uid, int $winId, int $now): array;
function hunt_win_set(PDO $pdo, int $winId, string $status, string $note, int $now): array;

function hunt_public(PDO $pdo, int $now, string $page = ''): array;
function hunt_me(PDO $pdo, array $cfg, string $uid, int $now): array;
function hunt_next(array $hunt, array $wallet, array $wins, array $chapters, int $now): ?string;
function hunt_session_field(PDO $pdo, array $cfg, string $uid, int $now): ?array;
function hunt_track(PDO $pdo, int $huntId, array $metrics, int $now): int;
function hunt_src_clean($raw): string;
```

Статусы ответа действий: `done | already | pending | wrong | login | banned |
closed | tg | not_sub | limit | balance | stale | bad`.

---

### Task 1: таблицы

**Files:** create `docs/migrations/2026-10-20-hunt.sql`, `tests/hunt_schema.php`;
modify `schema.sql`; test `tests/hunt_test.php`.

- [ ] Тест: каждое `CREATE TABLE IF NOT EXISTS hunt…` из миграции встречается
  в `schema.sql` дословно; в DDL миграции нет `DEFAULT` у `TEXT`.
- [ ] Прогнать — падает (файлов нет).
- [ ] Написать миграцию (9 таблиц: `hunts`, `hunt_chapters`, `hunt_tasks`,
  `hunt_secrets`, `hunt_wallets`, `hunt_ledger`, `hunt_tickets`, `hunt_wins`,
  `hunt_stats`; колонки — как в спеке, плюс `hunt_tickets.draw_pos` для
  «Перевыбрать» и `void_reason`), скопировать в `schema.sql`,
  `hunt_sqlite_tables(PDO)` в `tests/hunt_schema.php`.
- [ ] Прогнать — зелёный. Commit.

### Task 2: сезон, день, кошелёк, журнал

- [ ] Тесты: `hunt_day` — 2026-10-25 21:30 UTC = '2026-10-26' по Москве;
  `hunt_phase` во всех пяти состояниях; `hunt_award` дважды с одним `ref` —
  второе `already`, баланс и `earned` не удвоены; разные дни дают `days = 2`;
  `src` пишется только первый.
- [ ] Реализовать `hunt_ready`, `hunt_day`, `hunt_load`, `hunt_current`,
  `hunt_phase`, `hunt_chapters`, `hunt_open_chapters`, `hunt_wallet`,
  `hunt_award` (строка кошелька заводится INSERT'ом с перехватом 23000, потом
  транзакция: журнал → `UPDATE` кошелька).
- [ ] Зелёный. Commit.

### Task 3: задания

- [ ] Тесты:
  - `hunt_task_public` вопроса не содержит `correct` и `explain`;
  - неверный ответ → `wrong` + подсказка, журнал пуст; верный → `done` +
    `explain`; повтор → `already`;
  - `daily` даёт новую награду на следующий день по Москве, `once` — нет;
  - два задания одной группы (`grp`) — вторая награда `already`;
  - глава закрыта → `closed`; гость → `login`; забаненный → `banned`;
    после `sales_end_at` → `closed`;
  - `tg`: нет `tg_links` → `tg`; `getChatMember` = left → `not_sub`; сеть
    упала → `pending` и журнал пуст; member → `done`;
  - `visit` с чужой страницы → `bad`.
- [ ] Реализовать `hunt_tasks`, `hunt_task_ref`, `hunt_task_public`,
  `hunt_do_task`. Подписку проверяет `gw_member_state()` из
  `api/lib/giveaway.php`.
- [ ] Зелёный. Commit.

### Task 4: секретки

- [ ] Тесты: находка на своей странице → `done`; на чужой → `bad`; повтор →
  `already`; у второго человека та же секретка — снова `done`; `daily` —
  снова на следующий день; закрытая глава → `closed`.
- [ ] Реализовать `hunt_secrets`, `hunt_secret_ref`, `hunt_find_secret`.
- [ ] Зелёный. Commit.

### Task 5: билеты

- [ ] Тесты: 50 на балансе → билет, баланс 0, `earned` прежний, `tickets = 1`,
  номер `#000N`; повтор того же `seq` → `already`, второго билета нет;
  `seq` ≠ `tickets + 1` → `stale`; не хватает → `balance`; четвёртый при
  лимите 3 → `limit`; после `sales_end_at` → `closed`.
- [ ] Реализовать `hunt_ticket_no`, `hunt_buy_ticket`.
- [ ] Зелёный. Commit.

### Task 6: итоги и выдача

- [ ] Тесты (подменный `$rand`):
  - до `sales_end_at` → `not_ended`; второй раз → `drawn`;
  - у человека с тремя билетами максимум один приз;
  - аннулированные билеты и забаненные владельцы не выигрывают;
  - `deadline = now + claim_days·86400`; `hunt_expire_wins` переводит `won`
    после срока в `expired`;
  - `hunt_redraw` отдаёт приз следующему по `draw_pos` билету человека без
    приза; очередь кончилась → `none_left`;
  - `hunt_confirm_win` без Telegram → `tg`, с ним → `confirmed`; чужой
    выигрыш → `bad`;
  - `hunt_win_set`: `confirmed → queued → given`, назад нельзя.
- [ ] Реализовать `hunt_draw`, `hunt_expire_wins`, `hunt_redraw`,
  `hunt_confirm_win`, `hunt_win_set`.
- [ ] Зелёный. Commit.

### Task 7: что видит страница

- [ ] Тесты: `hunt_public` без сезона → `['hunt' => null]`; черновик не
  виден; закрытая глава — только заголовок и дата, без заданий; секретки
  страницы — только `id, spot, sprite`; в журнале у секреток нет `page`;
  после итогов — победители без просроченных.
  `hunt_me`: выполненные сегодня задания и найденные секретки, тексты
  находок, билеты, выигрыши. `hunt_next`: выигрыш важнее билета, билет важнее
  главы, глава важнее ежедневной награды. `hunt_session_field` без сезона —
  `null`.
- [ ] Реализовать `hunt_public`, `hunt_me`, `hunt_next`,
  `hunt_session_field`, `hunt_track`, `hunt_src_clean`.
- [ ] Зелёный. Commit.

### Task 8: эндпоинты

- [ ] Тесты: `handle_hunt_act` раздаёт по `act` (`task`, `answer`, `secret`,
  `ticket`, `confirm_win`), неизвестное → 400 `bad`; `handle_session`
  отдаёт поле `hunt`; `handle_hunt_stat` пишет только известные метрики;
  `dos_guard_test`: `hunt_me.php`, `hunt_act.php`, `hunt_stat.php` не
  кешируются, `hunt.php` — `lscache_public(HUNT_PUBLIC_CACHE)`, `.htaccess`
  пускает только пусто или `page=<ключ>`.
- [ ] Написать `api/hunt.php`, `api/hunt_me.php`, `api/hunt_act.php`,
  `api/hunt_stat.php`, поле в `api/session.php`, правило `.htaccess`.
- [ ] Зелёный. Commit.

### Task 9: стенд и проверка

- [ ] `tools/seed-hunt.php`: создаёт таблицы (SQLite стенда) и демо-сезон
  главы 1 — вводная тыква, вводный вопрос, по заданию каждого типа,
  секретки на нескольких страницах, глава 2 закрыта.
- [ ] Прогнать все PHP-тесты с `-d extension=gd`, node-тесты.
- [ ] Проверить имена `hunt.php`, `hunt_me.php`, `hunt_act.php`,
  `hunt_stat.php`, `/halloween` по EasyList, EasyPrivacy и AdGuard Russian.
- [ ] Commit, push, PR.
