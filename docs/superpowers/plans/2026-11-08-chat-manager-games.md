# Чат-менеджер, этап 3 (игры и отношения) — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** в любой группе с ботом — случайные команды, РП-действия, дуэли и кубы с приглашениями на кнопках, пары и браки, кланы. Всё по командам участников; проигрыш по умолчанию ничего не стоит, наказание включает модератор; любой раздел выключается через `дк <раздел> 6`.

**Architecture:** шесть новых файлов модуля `public_html/api/lib/cm/` — `fun.php` (случайные команды, РП, общее этапа 3), `offers.php` (приглашения с кнопками `cm:p:<id>:y|n`, счёт и исход игр, cron-тик), `dice.php`, `duel.php` (ходы кнопками `cm:d:<id>:s|a|k`), `love.php`, `clans.php` — на правилах этапов 1–2: функции с явными параметрами, контекст `$ctx` у обработчиков `cm_cmd_*`, тексты в `CM_TEXTS`, команды в `CM_COMMANDS`, разделы в `CM_SECTIONS`, условия команд без префикса — в `CM_BARE_ARGS`. Вид приглашения подключается функцией `cm_offer_yes_<вид>()`: `offers.php` её зовёт на «Принять». Случайность идёт через `cm_rand($ctx, …)`, тесты подменяют её `$ctx['rand']`. Таблицы — одной миграцией; пока её нет, команды этапа 3 отвечают «ещё не настроено» (`cm_ready3`), остальной бот работает.

**Tech Stack:** PHP 7.4 (бой) / 8.0 (локально, XAMPP), MySQL на бою, SQLite в тестах, Telegram Bot API (`sendDice`, `editMessageText`, `text_mention`), собственный тестовый каркас `tests/lib.php`.

**Спека:** `docs/superpowers/specs/2026-11-08-chat-manager-games-design.md`. Основа — этап 1 (PR #222, код в `public_html/api/lib/cm/`) и этап 2 (план `docs/superpowers/plans/2026-11-08-chat-manager-activity.md`, ветка `feat/chat-manager-activity`). Этот план опирается на код этапа 2 **ровно в том виде, как он в плане этапа 2**: якоря правок (Edit) взяты оттуда. Проверено так: задачи 1–5 плана этапа 2 применены к копии `3e1fca3` (все `tests/cm_*` зелёные, счёт как в плане этапа 2), поверх — задачи 1–7 этого плана, после каждой — все `tests/cm_*`, `php74_compat_test.php`, `telegram_test.php` зелёные, падения на шаге «тест падает» — те, что написаны. Если якорь не находится — скорее всего, исполнение этапа 2 отошло от его плана: найти то же место по смыслу.

## Global Constraints

- **PHP 7.4.** Нельзя: `str_contains`/`str_starts_with`/`str_ends_with`, `match`, `?->`, `#[...]`, union-типы (`int|string`), `mixed`, именованные аргументы, `catch` без переменной, `enum`, `readonly`. Можно: `fn() =>`, `??`, `??=`, типизированные параметры, `?int`, `?callable`, `[$a, $b] = …`. Проверка — `tests/php74_compat_test.php` (сканирует `public_html` рекурсивно).
- **Переносимый SQL.** Без `ON DUPLICATE KEY`, `ON CONFLICT`, `INSERT IGNORE`, `REPLACE`. Upsert: `UPDATE`, затем `INSERT` в `try/catch (PDOException $e)`. MySQL-ный `rowCount()` считает только изменённые строки — все «захваты» строк здесь (`status = 'open'` → другое, `turn_id` → другой) что-то меняют, поэтому `rowCount() === 0` честно значит «уже занято». `LIMIT` — числом в строке запроса (`' LIMIT ' . CM_CLAN_TOP`), не параметром. Один и тот же параметр дважды не повторять — `:u` и `:u2`.
- **Имена столбцов:** не `rank` (зарезервировано в MySQL 8), а `lvl`; открыт ли клан — `is_open`, не `open` (OPEN — ключевое слово MySQL). «Нет», «не закончено», «вместе», «в браке» — `0`, не `NULL`.
- **Ответы бота только на русском.** Все тексты — в `public_html/api/lib/cm/texts.php` (`CM_TEXTS`), из кода — через `cm_t()`.
- **Комментарии в PHP можно** (сервер их не отдаёт). В `public_html/js|css` и HTML — нельзя (здесь их не трогаем).
- **Новые PHP-файлы писать только инструментом Write**, не heredoc. Правки существующих — Edit; в `new_string` нельзя `\u…`, `\x…` и управляющих символов (Edit раскрывает их в настоящие байты), а `\n`, `\s`, `\d`, `\p{L}` в PHP-строках можно. Смайлы в текстах — готовыми символами, как в плане. После каждого коммита — `git show --stat HEAD`: строк `Bin` быть не должно.
- **PHP для тестов:** `/c/xampp/php/php.exe` (Git Bash, из корня worktree). Один тест — `/c/xampp/php/php.exe tests/cm_fun_test.php`. Набор — циклом с `-d extension=gd` (у XAMPP выключен GD), а не `tests/run_all.sh`: тот с `set -e` встанет на `images_test.php`.
- **Числа из SQLite в PHP 8.0 приходят строками** — в коде и тестах сравнивать через `(int)`; строки таблиц этапа 3 функции чтения сразу приводят к `int`.
- **В сообщениях тестов не писать `"«$var»"`:** байты «»» PHP читает как часть имени переменной и сыплет Warning. Писать `"«{$var}»"` или без кавычек-ёлочек.
- **Новые ключи настроек — только через `CM_DEFAULTS`** (`db.php`), иначе `cm_settings()` их отбросит. В этом этапе — `duel_outcome` и `dice_outcome` (Task 3), по умолчанию `[]`.
- **Даты по Москве** (`CM_TZ`): день — `cm_day()` этапа 2. Смену суток тесты проверяют на `CM_T2359` / `CM_T0001` этапа 2.
- **Бот сам ничего не делает.** Всё — ответ на команду или нажатие кнопки; cron только закрывает просроченное. Сообщения — через `cm_reply` / `cm_say` (бюджет этапа 1: 15 в минуту на группу), броски кубиков — через `cm_budget_take`. Кнопки правят существующее сообщение (`cm_edit` → `editMessageText`), новых не шлют. Устаревшая кнопка — ответ на кнопку «уже неактуально» (`offer_stale` этапа 1), без сообщений в чат. Временные ответы (`cm_reply($ctx, $text)`): случайные команды (кроме `!кто`), отказы и подсказки, `пара`, `брак`, «описание сохранено». Постоянные (`cm_reply($ctx, $text, false)`): `!кто`, РП, приглашения, топы, карточка клана, события (пара, расставание, развод, вступление и выход из клана, созыв, роспуск).
- **Случайность — только `cm_rand($ctx, $min, $max)`** (Task 1), не `random_int` напрямую: тесты подменяют её `$ctx['rand']` (`cm_seq([…])`).
- **Проигрыш без наказания по умолчанию.** Исход проигравшему — только если модератор включил `дуэли исход …` / `кубы исход …`, и только если оба игрока — не модераторы и не админы Telegram (`cm_exempt`).
- **Ветка:** `feat/chat-manager-games` — отрезается от готового `feat/chat-manager-activity`, в неё переносится коммит с этим планом. Перед Task 1 убедиться, что этап 2 на месте: `grep -c "function cm_profile_lines" public_html/api/lib/cm/profile.php` → `1`, все `tests/cm_*_test.php` зелёные. В `master` не пушить; в конце — push ветки и PR (Task 8).
- **Коммиты** заканчиваются строкой `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Решения поверх спеки

Спека их не запрещает, но и не называет; ревьюеру — чтобы не принять за отступление.

1. **Случайным командам таблицы не нужны.** `рандом`, `монетка`, `выбери`, `инфа`, `шар` не читают базу и работают и без миграции этапа 3; `!кто` нужна только статистика этапа 2 (`cm_need2`). «Ещё не настроено» (`cm_ready3`) отвечают команды, которым нужны таблицы этапа 3.
2. **`дуэли исход` и `кубы исход` — в разделе `триггеры`** (ранг 3, как `варны наказание` этапа 1), а не в `игры`. `дк` задаёт один уровень на весь раздел: в `игры` команда `дк игры 1` дала бы рангу 1 включать наказания. `дк игры 6` игры выключает, исход тогда просто не нужен.
3. **Ставка — для обоих или ни для кого.** Спека: «проигравшего с рангом ≥ 1 или админа Telegram исход не наказывает: такая игра идёт без ставки». Читаем как: если играет модератор или админ Telegram, игра без ставки для обоих — иначе обычный участник рисковал бы мутом, а модератор нет. Ставка пишется в приглашении («Ставка: проигравшему — мут 10 мин.»), чтобы адресат знал, на что соглашается. `удалить` в исходе не принимается: удалять нечего.
4. **`брак` с целью:** цель уже в браке — показать её брак («чужой» из спеки), иначе — предложение. `брак` без цели — свой брак. `пара` без конфликта: предложение — отдельная команда `встречаться`.
5. **Команды без префикса** — по правилам этапа 2 (`CM_BARE_ARGS`): РП, `дуэль`, `кубы`, `встречаться`, `пара`, `брак`, `клан`, `пригласить`, `исключить` — только ровно команда (и цель); `рандом` — числа, `выбери` — с «или» или запятой, `инфа`/`шанс`/`шар` — с текстом, `вступить` — с названием клана. Иначе «ударить бы его» ответом, «клан крутой у нас» или «выбери уже что-нибудь» вызывали бы бота.
6. **Кубы:** оба кубика — ответом на сообщение приглашения, итог — в нём же (правка, не новое сообщение). Telegram не бросил кубик (или кончился бюджет) — приглашение закрывается текстом «Кубы не бросились…», счёта нет.
7. **Дуэль:** «Прицелиться» при трёх прицелах — ответ на кнопку «больше нельзя», ход не переходит. Нажатие позже 2 минут закрывает дуэль сразу (не ждёт cron). Ход записывается условием `turn_id = нажавший`: второе быстрое нажатие не повторит ход.
8. **Кланы:** название и описание — без ссылок и мата, как город в анкете этапа 2 (видно всем, а команду фильтры не проверяют); «вступить в клан Волки» тоже понимается. Ушедшие из чата остаются в клане (спека: «автоматического развода при выходе нет» — так же и с кланами), но созыв их не зовёт.
9. **Приглашения:** «Отказаться» тоже жмёт только адресат. Если кончился бюджет сообщений группы, приглашения нет (строка закрывается сразу).
10. **Переезд группы в супергруппу** (`cm_migrate`) уносит и таблицы этапа 3, если его миграция выполнена; `cm_clans` с уникальным именем в чате уступает место, как таблицы с ключом по чату.
11. **Имена** в текстах — как в Telegram (`cm_display_name` автора, `cm_user_name` из базы для остальных) и не склоняются: «💔 Вася и Петя расстались», «У Петя уже есть пара». Победа — «🏆 Победитель — Вася!» (без рода: пола из анкеты у человека может и не быть).

## Карта файлов

| Файл | Что | Задача |
| --- | --- | --- |
| `docs/migrations/2026-11-08-chat-manager-games.sql` | таблицы `cm_offers`, `cm_games`, `cm_game_stats`, `cm_couples`, `cm_marriages`, `cm_clans`, `cm_clan_members` | 1 |
| `tests/cm_schema.php` | те же таблицы для SQLite | 1 |
| `tests/cm_helpers.php` | `cm_seq` (1), `cm_press` (3) | 1, 3 |
| `public_html/api/lib/cm/fun.php` | `cm_ready3`, `cm_need3`, `cm_rand`, `cm_plural`, случайные команды, `!кто` (1); РП, `cm_botlike`, `cm_couple_xp` (2) | 1, 2 |
| `public_html/api/lib/cm/offers.php` | приглашения, `cm_edit`, `cm_games_tick`, счёт, ставка и исход игр | 3, 4 |
| `public_html/api/lib/cm/dice.php` | кубы | 3 |
| `public_html/api/lib/cm/duel.php` | дуэль | 4 |
| `public_html/api/lib/cm/love.php` | пары и браки, `cm_actor_name`, `cm_other_err` | 5 |
| `public_html/api/lib/cm/clans.php` | кланы | 6 |
| `public_html/api/lib/cm/profile.php` | `cm_profile_lines3`, вызов из `cm_profile_lines` | 7 |
| `public_html/api/lib/cm/parse.php` | строки `CM_COMMANDS`, `CM_BARE_ARGS`, `CM_PREFIX_ONLY` | 1–6 |
| `public_html/api/lib/cm/texts.php` | тексты, `помощь` | 1–7 |
| `public_html/api/lib/cm/ranks.php` | `CM_SECTIONS`: `игры`, `рп`, `отношения`, `кланы` | 1, 2, 5, 6 |
| `public_html/api/lib/cm/db.php` | `cm_migrate` с таблицами этапа 3 (1), `CM_DEFAULTS` (3) | 1, 3 |
| `public_html/api/lib/cm/router.php` | `CM_PREFIX_ONLY` в `cm_run_command` (1); кнопки `p` (3) и `d` (4) в `cm_on_callback` | 1, 3, 4 |
| `public_html/api/lib/cm/cron.php` | ключ `games` в `cm_cron_tick` | 3 |
| `public_html/api/lib/cm/cm.php` | части `fun`, `offers`, `dice`, `duel`, `love`, `clans` после этапа 2, до `router` | 1, 3–6 |
| `docs/chat-manager.md` | запуск и устройство этапа 3 | 8 |

Тесты: `tests/cm_fun_test.php` (1, 2), `cm_offers_test.php` (3), `cm_dice_test.php` (3), `cm_duel_test.php` (4), `cm_love_test.php` (5), `cm_clans_test.php` (6), правки `tests/cm_router_test.php` (3, 4, 7) и `tests/cm_profile_test.php` (7). Все подхватываются по маске `*_test.php`.

Каждый новый тест-файл начинается так же, как в этапах 1–2:

```php
<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';
```

Помощники этапов 1–2 (`tests/cm_helpers.php`), которыми пользуются тесты ниже: `cm_test_pdo()` (SQLite в памяти со всеми таблицами `cm_*`), `cm_ctx_for($pdo, &$log, $actor, $text = '', $extra = [], $answers = [], $now = CM_NOW)` (контекст обработчика; `$extra` дописывается в сообщение — например `reply_to_message`; `$answers` — ответы подставного Telegram по методу: значение или функция от параметров), `cm_do($ctx, $text)` (обработчик команды напрямую, без проверки ранга; не команда — исключение «не команда: …»), `cm_rec(&$log)`, `cm_calls($log, $method)`, `cm_texts($log)`, `cm_last_text($log)`, `cm_gmsg($from, $text, $extra = [])` (имя автора — `U<id>`), `cm_person($pdo, $id, $username, $name = '')`, `cm_lvl($pdo, $userId, $lvl)`, `cm_cfg()`. Константы: `CM_NOW` = 1790000000 (21.09.2026 17:13 МСК, день `20260921`), `CM_T2359`, `CM_T0001`, `CM_CHAT`, `CM_BOT`, `CM_OWNER` (1, создатель группы, ранг 5), `CM_ADMIN` (2, админ Telegram без ранга). Подставной `$send` на `sendMessage` отвечает `message_id` = 5000 + номер вызова, на остальное — `['ok' => true, 'result' => true]`.

## Опора на этап 2

Имена и сигнатуры — из раздела «Что берёт этап 3» и блоков Interfaces плана этапа 2:

- `cm_ready2(PDO $pdo): bool`, `cm_need2(array $ctx): bool` — `!кто` (Task 1); `cm_ready3` / `cm_need3` сделаны по их образцу, текст тот же — `not_ready2`.
- `cm_day(int $ts): int` — «инфа» на день и опыт пары за сутки (Task 1, 2); `cm_day_from(int $now, int $days): int` — активность кланов своим `JOIN cm_stats … AND day >= :d` (Task 6).
- `cm_stats_top(PDO $pdo, int $chatId, int $days, int $now, int $limit): array` — кандидаты `!кто` (Task 1).
- `cm_num(int $n): string` — числа в `рандом` и кланах.
- `cm_target_or_self(array $ctx, string &$args): ?array` — `пара` (Task 5).
- `cm_profile_get(PDO $pdo, int $userId): array` — пол для формы глагола РП (Task 2); `cm_profile_put` — только в тестах.
- `cm_section_on(array $chat, string $section): bool` и `cm_profile_lines(array $ctx, int $userId, string $name): array` — строки этапа 3 в профиле после «Награды», перед «Варны» (Task 7).
- `CM_BARE_ARGS` / `cm_bare_ok()` (parse.php, первая строка `cm_run_command`) — дописываются строки этапа 3; перед ней встаёт проверка `CM_PREFIX_ONLY` (Task 1).
- `CM_CHAT_TABLES2_PK` / `CM_CHAT_TABLES2_ROWS` и блок `cm_ready2` в `cm_migrate` — якорь для таблиц этапа 3 (Task 1).
- `CM_TG_SERVICE_ID` (777000, rep.php) — служебный Telegram считается ботом (`cm_botlike`, Task 2).
- Тесты: `CM_T2359`, `CM_T0001`, таблицы `cm_stats`, `cm_rep`, `cm_rep_score()`; в `cm_router_test.php` — тест «cron: таймеры и очередь» и `rt_run()`; в `cm_profile_test.php` — `run_tests();` последней строкой.

Якоря правок в файлах этапа 2 (должны найтись ровно один раз): в `parse.php` — строка `'помощь' => …` и последняя строка `CM_BARE_ARGS` `'profile_of'  => '~^$~',`; в `router.php` — строки ` *    цели подходит под её шаблон (cm_bare_ok, parse.php).` и `if (!$cmd['prefixed'] && !cm_bare_ok($ctx, $cmd)) { return false; }`; в `ranks.php` — `'репутация', 'награды', 'анкета'];`; в `texts.php` — `'profile_about' => …` и строка помощи `🏅 Награды: …`; в `cm.php` — `'router', 'cron'] as $cm_part) {`; в `profile.php` — `if ($awards) { … }` и `function cm_profile_send(`.

## Порядок исполнения

Задачи идут строго по порядку: каждая следующая дописывает те же строки `parse.php`, `texts.php`, `ranks.php`, `cm.php`, что и предыдущая, и пользуется функциями предыдущих. Деление на три сессии:

- **Сессия 1 — Task 1–3:** таблицы и случайные команды, РП и опыт пары, приглашения с кубами и исходом (Task 3 — самая большая: `offers.php`, кнопка `p`, cron).
- **Сессия 2 — Task 4–5:** дуэль (кнопка `d`, просрочка хода), пары и браки.
- **Сессия 3 — Task 6–8:** кланы, профиль и помощь, описание, полный прогон и PR.

В конце сессии — записка в общей папке сессий: коммиты, счёт тестов, отступления от плана.

---

### Task 1: Таблицы этапа 3 и случайные команды

**Files:**
- Create: `docs/migrations/2026-11-08-chat-manager-games.sql`
- Create: `public_html/api/lib/cm/fun.php`
- Modify: `tests/cm_schema.php`, `tests/cm_helpers.php`
- Modify: `public_html/api/lib/cm/db.php` (`cm_migrate`), `parse.php` (команды, `CM_BARE_ARGS`, `CM_PREFIX_ONLY`), `router.php` (`cm_run_command`), `ranks.php` (`CM_SECTIONS`), `texts.php`, `cm.php`
- Test: `tests/cm_fun_test.php`

**Interfaces:**
- Consumes (этап 2): `cm_ready2(PDO $pdo): bool`, `cm_need2(array $ctx): bool`, `cm_day(int $ts): int`, `cm_num(int $n): string`, `cm_stats_top(PDO $pdo, int $chatId, int $days, int $now, int $limit): array` (`[['user_id' => int, 'msgs' => int], …]`, без ушедших), `CM_BARE_ARGS`, `cm_bare_ok(array $ctx, array $cmd): bool`, `CM_CHAT_TABLES2_PK`, `CM_CHAT_TABLES2_ROWS`, `CM_T2359`, `CM_T0001`; этап 1: `cm_reply`, `cm_t`, `cm_norm`, `cm_user_name`, `cm_migrate`, `cm_run_command`.
- Produces:
  - `cm_ready3(PDO $pdo): bool` (есть ли `cm_offers`), `cm_need3(array $ctx): bool` (нет — ответ `not_ready2` и `false`)
  - `cm_rand(array $ctx, int $min, int $max): int` — `random_int`, а если в `$ctx['rand']` функция `(int $min, int $max): int` — она (тесты)
  - `cm_plural(int $n, array $forms): string` — `[1, 2, 5]`-формы: `cm_plural(5, ['победа', 'победы', 'побед'])` → `'побед'`
  - `cm_choose_options(string $s): array`, `cm_chance(string $text, int $now): int` (0–100)
  - `CM_BALL` (10 ответов), `CM_RAND_MAX` (10⁹), `CM_CHOOSE_MAX` (10), `CM_WHO_DAYS` (7)
  - обработчики `cm_cmd_rand`, `cm_cmd_coin`, `cm_cmd_choose`, `cm_cmd_chance`, `cm_cmd_ball`, `cm_cmd_who`
  - `CM_PREFIX_ONLY = ['who']` (parse.php), первая строка `cm_run_command`
  - `CM_CHAT_TABLES3_PK`, `CM_CHAT_TABLES3_ROWS` (db.php)
  - раздел `игры` в `CM_SECTIONS`
  - Тексты: `rand_usage`, `rand_done`, `coin_heads`, `coin_tails`, `choose_usage`, `choose_done`, `chance_usage`, `chance_done`, `ball_usage`, `ball_done`, `who_done`, `who_any`, `who_empty`
  - Тесты: `cm_seq(array $values): callable` (tests/cm_helpers.php) — подставная случайность: числа по очереди, последнее повторяется

- [ ] **Step 1: Миграция**

Write `docs/migrations/2026-11-08-chat-manager-games.sql`:

```sql
-- Чат-менеджер, этап 3 (игры и отношения): приглашения, дуэли, кубы, пары,
-- браки, кланы. Код — api/lib/cm/fun.php, offers.php, dice.php, duel.php,
-- love.php, clans.php; описание — docs/chat-manager.md. Запускать один раз,
-- ПОСЛЕ 2026-11-08-chat-manager-activity.sql. Пока этих таблиц нет, команды
-- этапа 3 отвечают «ещё не настроено» (cm_ready3 в api/lib/cm/fun.php), а
-- остальной бот работает как раньше.
--
-- «Нет», «не закончено» — 0, а не NULL. Открыт ли клан — is_open: OPEN —
-- ключевое слово MySQL (курсоры), имя столбца с ним не рискуем.

-- Приглашения с кнопками «Принять» / «Отказаться». kind: duel, dice, couple,
-- marry, remarry, clan; ref_id — клан (clan) или прежний брак (remarry).
-- status: open, yes, no, gone.
CREATE TABLE IF NOT EXISTS cm_offers (
  id         INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
  chat_id    BIGINT          NOT NULL,
  kind       VARCHAR(8)      NOT NULL,
  from_id    BIGINT          NOT NULL,
  to_id      BIGINT          NOT NULL,
  ref_id     INT UNSIGNED    NOT NULL DEFAULT 0,
  msg_id     BIGINT          NOT NULL DEFAULT 0,
  expires_at BIGINT UNSIGNED NOT NULL,
  status     VARCHAR(4)      NOT NULL DEFAULT 'open',
  KEY idx_open (chat_id, status, expires_at),
  KEY idx_due (status, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Идущие дуэли. state — JSON прицелов {"a":0,"b":0}; status: on, done.
CREATE TABLE IF NOT EXISTS cm_games (
  id         INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
  chat_id    BIGINT          NOT NULL,
  kind       VARCHAR(8)      NOT NULL,
  a_id       BIGINT          NOT NULL,
  b_id       BIGINT          NOT NULL,
  turn_id    BIGINT          NOT NULL,
  state      VARCHAR(255)    NOT NULL DEFAULT '{}',
  msg_id     BIGINT          NOT NULL DEFAULT 0,
  status     VARCHAR(4)      NOT NULL DEFAULT 'on',
  winner_id  BIGINT          NOT NULL DEFAULT 0,
  updated_at BIGINT UNSIGNED NOT NULL,
  KEY idx_due (status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Счёт дуэлей и кубов. kind: duel, dice.
CREATE TABLE IF NOT EXISTS cm_game_stats (
  chat_id BIGINT       NOT NULL,
  user_id BIGINT       NOT NULL,
  kind    VARCHAR(8)   NOT NULL,
  wins    INT UNSIGNED NOT NULL DEFAULT 0,
  losses  INT UNSIGNED NOT NULL DEFAULT 0,
  draws   INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (chat_id, user_id, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Пары. Опыт — от РП, не больше 20 в сутки по Москве: xp_day (ГГГГММДД) и
-- xp_today. ended_at 0 — вместе.
CREATE TABLE IF NOT EXISTS cm_couples (
  id       INT UNSIGNED      NOT NULL AUTO_INCREMENT PRIMARY KEY,
  chat_id  BIGINT            NOT NULL,
  a_id     BIGINT            NOT NULL,
  b_id     BIGINT            NOT NULL,
  since    BIGINT UNSIGNED   NOT NULL,
  xp       INT UNSIGNED      NOT NULL DEFAULT 0,
  xp_day   INT UNSIGNED      NOT NULL DEFAULT 0,
  xp_today SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ended_at BIGINT UNSIGNED   NOT NULL DEFAULT 0,
  KEY idx_a (chat_id, a_id),
  KEY idx_b (chat_id, b_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Браки. ended_at 0 — в браке; ended_by — кто развёлся.
CREATE TABLE IF NOT EXISTS cm_marriages (
  id       INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
  chat_id  BIGINT          NOT NULL,
  a_id     BIGINT          NOT NULL,
  b_id     BIGINT          NOT NULL,
  since    BIGINT UNSIGNED NOT NULL,
  ended_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
  ended_by BIGINT          NOT NULL DEFAULT 0,
  KEY idx_a (chat_id, a_id),
  KEY idx_b (chat_id, b_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Кланы. name_key — имя в нижнем регистре и с «е» вместо «ё»: имя уникально
-- в чате без учёта регистра.
CREATE TABLE IF NOT EXISTS cm_clans (
  id         INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
  chat_id    BIGINT          NOT NULL,
  name       VARCHAR(32)     NOT NULL,
  name_key   VARCHAR(32)     NOT NULL,
  owner_id   BIGINT          NOT NULL,
  about      VARCHAR(200)    NOT NULL DEFAULT '',
  is_open    TINYINT         NOT NULL DEFAULT 1,
  called_at  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at BIGINT UNSIGNED NOT NULL,
  UNIQUE KEY uq_name (chat_id, name_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Кто в каком клане: в чате человек — не больше чем в одном.
CREATE TABLE IF NOT EXISTS cm_clan_members (
  chat_id   BIGINT          NOT NULL,
  user_id   BIGINT          NOT NULL,
  clan_id   INT UNSIGNED    NOT NULL,
  joined_at BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (chat_id, user_id),
  KEY idx_clan (clan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- [ ] **Step 2: Таблицы для SQLite и подставная случайность**

Edit `tests/cm_schema.php` — перед строкой

```php
    $pdo->exec("CREATE TABLE IF NOT EXISTS tg_links (
```

вставить (сама строка `tg_links` остаётся следом):

```php
    // этап 3 — docs/migrations/2026-11-08-chat-manager-games.sql
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_offers (
        id INTEGER PRIMARY KEY AUTOINCREMENT, chat_id INTEGER NOT NULL, kind TEXT NOT NULL,
        from_id INTEGER NOT NULL, to_id INTEGER NOT NULL, ref_id INTEGER NOT NULL DEFAULT 0,
        msg_id INTEGER NOT NULL DEFAULT 0, expires_at INTEGER NOT NULL, status TEXT NOT NULL DEFAULT 'open')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_games (
        id INTEGER PRIMARY KEY AUTOINCREMENT, chat_id INTEGER NOT NULL, kind TEXT NOT NULL,
        a_id INTEGER NOT NULL, b_id INTEGER NOT NULL, turn_id INTEGER NOT NULL, state TEXT NOT NULL DEFAULT '{}',
        msg_id INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT 'on', winner_id INTEGER NOT NULL DEFAULT 0,
        updated_at INTEGER NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_game_stats (
        chat_id INTEGER NOT NULL, user_id INTEGER NOT NULL, kind TEXT NOT NULL, wins INTEGER NOT NULL DEFAULT 0,
        losses INTEGER NOT NULL DEFAULT 0, draws INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (chat_id, user_id, kind))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_couples (
        id INTEGER PRIMARY KEY AUTOINCREMENT, chat_id INTEGER NOT NULL, a_id INTEGER NOT NULL, b_id INTEGER NOT NULL,
        since INTEGER NOT NULL, xp INTEGER NOT NULL DEFAULT 0, xp_day INTEGER NOT NULL DEFAULT 0,
        xp_today INTEGER NOT NULL DEFAULT 0, ended_at INTEGER NOT NULL DEFAULT 0)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_marriages (
        id INTEGER PRIMARY KEY AUTOINCREMENT, chat_id INTEGER NOT NULL, a_id INTEGER NOT NULL, b_id INTEGER NOT NULL,
        since INTEGER NOT NULL, ended_at INTEGER NOT NULL DEFAULT 0, ended_by INTEGER NOT NULL DEFAULT 0)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_clans (
        id INTEGER PRIMARY KEY AUTOINCREMENT, chat_id INTEGER NOT NULL, name TEXT NOT NULL, name_key TEXT NOT NULL,
        owner_id INTEGER NOT NULL, about TEXT NOT NULL DEFAULT '', is_open INTEGER NOT NULL DEFAULT 1,
        called_at INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL, UNIQUE (chat_id, name_key))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_clan_members (
        chat_id INTEGER NOT NULL, user_id INTEGER NOT NULL, clan_id INTEGER NOT NULL, joined_at INTEGER NOT NULL,
        PRIMARY KEY (chat_id, user_id))");
```

Edit `tests/cm_helpers.php`: после строки

```php
const CM_T0001 = 1790024460;
```

добавить

```php

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
```

- [ ] **Step 3: Падающий тест**

Write `tests/cm_fun_test.php`:

```php
<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';

// Случайные команды и РП (этап 3): api/lib/cm/fun.php, CM_PREFIX_ONLY для
// «!кто» (parse.php, router.php), переезд группы с таблицами этапа 3.

// Сообщение через проверку команды, как в роутере: true — это команда.
function fn_run(PDO $pdo, array &$log, int $actor, string $text, array $extra = []): bool {
    return cm_run_command(cm_ctx_for($pdo, $log, $actor, $text, $extra), cm_parse($text, CM_BOT));
}

test('таблицы этапа 3: есть — готово, нет — «ещё не настроено», этап 2 жив', function () {
    assert_eq(true, cm_ready3(cm_test_pdo()));
    $pdo = cm_test_pdo();
    $pdo->exec('DROP TABLE cm_offers');
    assert_eq(false, cm_ready3($pdo));
    assert_eq(true, cm_ready2($pdo));
    $log = [];
    assert_eq(false, cm_need3(cm_ctx_for($pdo, $log, 7)));
    assert_eq(cm_t('not_ready2'), cm_last_text($log));
});

test('слово при числе', function () {
    $f = ['победа', 'победы', 'побед'];
    $got = [];
    foreach ([0, 1, 2, 4, 5, 11, 12, 14, 21, 22, 25, 101, 111] as $n) { $got[] = $n . ' ' . cm_plural($n, $f); }
    assert_eq(['0 побед', '1 победа', '2 победы', '4 победы', '5 побед', '11 побед', '12 побед', '14 побед',
        '21 победа', '22 победы', '25 побед', '101 победа', '111 побед'], $got);
});

test('рандом: 1–100, одно число, два в любом порядке, пределы; ответ временный', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $calls = [];
    $ctx = cm_ctx_for($pdo, $log, 7);
    $ctx['rand'] = function (int $min, int $max) use (&$calls): int {
        $calls[] = [$min, $max];
        return $max;
    };
    cm_do($ctx, 'рандом');
    assert_eq('🎲 Число от 1 до 100: 100', cm_last_text($log));
    cm_do($ctx, 'рандом 50');
    cm_do($ctx, 'рандом 20 10');
    cm_do($ctx, 'рандом -5 5');
    assert_eq('🎲 Число от -5 до 5: 5', cm_last_text($log));
    cm_do($ctx, 'рандом 1000000000');
    assert_eq('🎲 Число от 1 до 1 000 000 000: 1 000 000 000', cm_last_text($log));
    assert_eq([[1, 100], [1, 50], [10, 20], [-5, 5], [1, 1000000000]], $calls);
    foreach (['рандом 1000000001', 'рандом много', 'рандом 1 2 3'] as $bad) {
        cm_do($ctx, $bad);
        assert_eq(cm_t('rand_usage'), cm_last_text($log), $bad);
    }
    assert_eq(8, (int)$pdo->query('SELECT COUNT(*) FROM cm_queue')->fetchColumn(), 'все 8 ответов удалятся через минуту');
});

test('монетка и шар — по подставной случайности', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, 7);
    $ctx['rand'] = cm_seq([0, 1, 0, 9]);
    cm_do($ctx, 'монетка');
    assert_eq(cm_t('coin_heads'), cm_last_text($log));
    cm_do($ctx, 'монетка');
    assert_eq(cm_t('coin_tails'), cm_last_text($log));
    cm_do($ctx, 'шар сдам экзамен?');
    assert_eq('🎱 Да.', cm_last_text($log));
    cm_do($ctx, 'шар а завтра?');
    assert_eq('🎱 ' . CM_BALL[9], cm_last_text($log));
    assert_eq(10, count(CM_BALL));
    cm_do($ctx, 'шар');
    assert_eq(cm_t('ball_usage'), cm_last_text($log));
});

test('выбери: «или» и запятые, от 2 до 10 вариантов', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, 7);
    $ctx['rand'] = function (int $min, int $max): int { return $max; };
    cm_do($ctx, 'выбери чай или кофе?');
    assert_eq('🤔 Выбираю: кофе', cm_last_text($log));
    cm_do($ctx, 'выбери Москва, Питер ИЛИ Казань');
    assert_eq('🤔 Выбираю: Казань', cm_last_text($log));
    assert_eq(['a', 'b', 'c d'], cm_choose_options('a, b или c d'));
    foreach (['выбери чай', 'выбери ' . implode(', ', range(1, 11)), 'выбери , или'] as $bad) {
        cm_do($ctx, $bad);
        assert_eq(cm_t('choose_usage'), cm_last_text($log), $bad);
    }
});

test('инфа и шанс: один текст в один день — одно число, назавтра — своё', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $want = crc32('завтра будет дождь|20260921') % 101;
    $next = crc32('завтра будет дождь|20260922') % 101;
    assert_eq(true, $want !== $next, 'пример различает дни');
    cm_do(cm_ctx_for($pdo, $log, 7, '', [], [], CM_T2359), 'инфа Завтра  будет дождь');
    assert_eq('🔮 Вероятность: ' . $want . '%', cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, 8, '', [], [], CM_NOW), 'шанс завтра будет дождь');
    assert_eq('🔮 Вероятность: ' . $want . '%', cm_last_text($log), 'другой человек, тот же день');
    cm_do(cm_ctx_for($pdo, $log, 7, '', [], [], CM_T0001), 'инфа завтра будет дождь');
    assert_eq('🔮 Вероятность: ' . $next . '%', cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, 7), 'инфа');
    assert_eq(cm_t('chance_usage'), cm_last_text($log));
});

test('!кто: из писавших за неделю, без ушедших; ответ постоянный', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 7, 'vasya', 'Вася');
    cm_person($pdo, 8, 'petya', 'Петя');
    $ins = $pdo->prepare('INSERT INTO cm_stats (chat_id, user_id, day, msgs) VALUES (:c, :u, :d, :m)');
    $ins->execute([':c' => CM_CHAT, ':u' => 7, ':d' => 20260921, ':m' => 5]);
    $ins->execute([':c' => CM_CHAT, ':u' => 8, ':d' => 20260915, ':m' => 9]);
    $ins->execute([':c' => CM_CHAT, ':u' => 9, ':d' => 20260921, ':m' => 50]);
    $ins->execute([':c' => CM_CHAT, ':u' => 10, ':d' => 20260914, ':m' => 99]);   // 8-й день — уже не неделя
    cm_member_set($pdo, CM_CHAT, 9, ['left_at' => CM_NOW]);
    $asked = [];
    $ctx = cm_ctx_for($pdo, $log, 7);
    $ctx['rand'] = function (int $min, int $max) use (&$asked): int {
        $asked[] = [$min, $max];
        return 0;
    };
    cm_do($ctx, '!кто сегодня дежурит?');
    assert_eq('🎯 Кто сегодня дежурит? Думаю, Петя.', cm_last_text($log));
    assert_eq([[0, 1]], $asked, 'двое: Петя (9) и Вася (5)');
    cm_do($ctx, '!кто');
    assert_eq('🎯 Думаю, Петя.', cm_last_text($log));
    assert_eq(0, (int)$pdo->query('SELECT COUNT(*) FROM cm_queue')->fetchColumn(), 'не удаляется');
    cm_do(cm_ctx_for(cm_test_pdo(), $log, 7), '!кто');
    assert_eq(cm_t('who_empty'), cm_last_text($log));
});

test('без префикса: «кто знает» — речь, «!кто» — команда; «кто я» и «кто ты» — как раньше', function () {
    $pdo = cm_test_pdo();
    $log = [];
    assert_eq(false, fn_run($pdo, $log, 7, 'кто знает, где ключи'));
    assert_eq(false, fn_run($pdo, $log, 7, 'кто'));
    assert_eq([], cm_calls($log, 'sendMessage'));
    assert_eq(true, fn_run($pdo, $log, 7, '!кто знает'));
    assert_eq(cm_t('who_empty'), cm_last_text($log));
    assert_eq(true, fn_run($pdo, $log, 7, 'кто я'));
    assert_eq(0, strpos(cm_last_text($log), "👤 U7\n"));
    $reply = ['reply_to_message' => ['message_id' => 70, 'from' => ['id' => 20, 'first_name' => 'Петя']]];
    assert_eq(true, fn_run($pdo, $log, 7, 'кто ты', $reply));
    assert_eq(0, strpos(cm_last_text($log), "👤 Петя\n"));
});

test('без префикса: рандом, монетка, выбери, инфа, шар — по шаблону, иначе речь', function () {
    $pdo = cm_test_pdo();
    $log = [];
    foreach (['рандом', 'рандом 1 100', 'монетка', 'выбери чай или кофе', 'выбери чай, кофе', 'инфа он прав',
              'шанс есть', 'шар сдам?'] as $text) {
        assert_eq(true, fn_run($pdo, $log, 7, $text), $text);
    }
    $n = count(cm_calls($log, 'sendMessage'));
    foreach (['рандом число', 'монетка упала', 'выбери уже что-нибудь', 'инфа', 'шар', "инфа\nвторая строка"] as $text) {
        assert_eq(false, fn_run($pdo, $log, 7, $text), $text);
    }
    assert_eq($n, count(cm_calls($log, 'sendMessage')), 'обычная речь — без ответа');
});

test('дк игры 6 выключает случайные команды', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), 'дк игры 6');
    assert_eq(cm_t('access_done', ['section' => 'игры', 'level' => 6]), cm_last_text($log));
    assert_eq(true, fn_run($pdo, $log, 7, '!монетка'));
    assert_eq(cm_t('cmd_off'), cm_last_text($log));
    assert_eq(false, fn_run($pdo, $log, 7, 'монетка'), 'без префикса — молча');
});

test('случайным командам таблицы этапа 3 не нужны', function () {
    $pdo = cm_test_pdo();
    $pdo->exec('DROP TABLE cm_offers');
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, 7);
    $ctx['rand'] = cm_seq([1]);
    cm_do($ctx, 'монетка');
    assert_eq(cm_t('coin_tails'), cm_last_text($log));
});

test('переезд группы уносит таблицы этапа 3, старые строки главнее', function () {
    $pdo = cm_test_pdo();
    cm_chat_ensure($pdo, ['id' => -500, 'title' => 'Старая'], CM_NOW);
    $c = CM_CHAT;
    $pdo->exec("INSERT INTO cm_offers (chat_id, kind, from_id, to_id, ref_id, msg_id, expires_at, status) VALUES (-500, 'duel', 7, 8, 0, 1, 0, 'open')");
    $pdo->exec("INSERT INTO cm_games (chat_id, kind, a_id, b_id, turn_id, state, msg_id, status, winner_id, updated_at) VALUES (-500, 'duel', 7, 8, 7, '{}', 1, 'on', 0, 0)");
    $pdo->exec("INSERT INTO cm_game_stats (chat_id, user_id, kind, wins, losses, draws) VALUES (-500, 7, 'duel', 3, 0, 0)");
    $pdo->exec("INSERT INTO cm_game_stats (chat_id, user_id, kind, wins, losses, draws) VALUES ($c, 7, 'duel', 1, 0, 0)");
    $pdo->exec("INSERT INTO cm_couples (chat_id, a_id, b_id, since, xp, xp_day, xp_today, ended_at) VALUES (-500, 7, 8, 0, 5, 0, 0, 0)");
    $pdo->exec("INSERT INTO cm_marriages (chat_id, a_id, b_id, since, ended_at, ended_by) VALUES (-500, 7, 8, 0, 0, 0)");
    $pdo->exec("INSERT INTO cm_clans (chat_id, name, name_key, owner_id, about, is_open, called_at, created_at) VALUES (-500, 'Волки', 'волки', 7, '', 1, 0, 0)");
    $pdo->exec("INSERT INTO cm_clans (chat_id, name, name_key, owner_id, about, is_open, called_at, created_at) VALUES ($c, 'ВОЛКИ', 'волки', 9, '', 1, 0, 0)");
    $pdo->exec("INSERT INTO cm_clan_members (chat_id, user_id, clan_id, joined_at) VALUES (-500, 7, 1, 0)");
    cm_migrate($pdo, -500, CM_CHAT);
    foreach (['cm_offers', 'cm_games', 'cm_game_stats', 'cm_couples', 'cm_marriages', 'cm_clans', 'cm_clan_members'] as $t) {
        assert_eq(1, (int)$pdo->query("SELECT COUNT(*) FROM $t WHERE chat_id = $c")->fetchColumn(), $t);
        assert_eq(0, (int)$pdo->query("SELECT COUNT(*) FROM $t WHERE chat_id = -500")->fetchColumn(), $t . ' под старым id');
    }
    assert_eq(3, (int)$pdo->query("SELECT wins FROM cm_game_stats WHERE chat_id = $c")->fetchColumn());
    assert_eq(7, (int)$pdo->query("SELECT owner_id FROM cm_clans WHERE chat_id = $c")->fetchColumn());
});

test('переезд без таблиц этапа 3 — как раньше', function () {
    $pdo = cm_test_pdo();
    foreach (['cm_offers', 'cm_games', 'cm_game_stats', 'cm_couples', 'cm_marriages', 'cm_clans', 'cm_clan_members'] as $t) {
        $pdo->exec("DROP TABLE $t");
    }
    cm_chat_ensure($pdo, ['id' => -500, 'title' => 'Старая'], CM_NOW);
    $pdo->exec('INSERT INTO cm_rep (chat_id, user_id, score) VALUES (-500, 7, 4)');
    cm_migrate($pdo, -500, CM_CHAT);
    assert_eq(4, cm_rep_score($pdo, CM_CHAT, 7));
});

run_tests();
```

- [ ] **Step 4: Убедиться, что тест падает**

Run: `/c/xampp/php/php.exe tests/cm_fun_test.php`
Expected: FAIL — первая строка `ERROR - таблицы этапа 3: …: Call to undefined function cm_ready3()`.

- [ ] **Step 5: Случайные команды**

Write `public_html/api/lib/cm/fun.php`:

```php
<?php
// Случайные команды и РП (этап 3): рандом, монетка, выбери, инфа / шанс, шар,
// !кто, РП-действия. Здесь же общее для всего этапа 3: cm_ready3, cm_rand,
// cm_plural. Таблицы этапа 3 — docs/migrations/2026-11-08-chat-manager-games.sql.
// Приглашения — offers.php, кубы — dice.php, дуэль — duel.php, пары и браки
// — love.php, кланы — clans.php.

const CM_RAND_MAX   = 1000000000;
const CM_CHOOSE_MAX = 10;
const CM_WHO_DAYS   = 7;
const CM_BALL = ['Да.', 'Нет.', 'Скорее всего.', 'Не думаю.', 'Определённо да.', 'Даже не надейтесь.',
                 'Возможно.', 'Спросите позже.', 'Знаки говорят — да.', 'Сомневаюсь.'];

// Миграция этапа 3 выполняется руками после этапа 2. Пока её нет, команды,
// которым нужны таблицы этапа 3, отвечают «ещё не настроено». Случайным
// командам база не нужна — они работают всегда.
function cm_ready3(PDO $pdo): bool {
    try {
        $pdo->query('SELECT 1 FROM cm_offers LIMIT 1');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

// Первая строка обработчика, которому нужны таблицы этапа 3.
function cm_need3(array $ctx): bool {
    if (cm_ready3($ctx['pdo'])) { return true; }
    cm_reply($ctx, cm_t('not_ready2'));
    return false;
}

// Случайное целое от $min до $max включительно. Тесты подменяют источник:
// $ctx['rand'] — функция (int $min, int $max): int.
function cm_rand(array $ctx, int $min, int $max): int {
    $f = $ctx['rand'] ?? null;
    return is_callable($f) ? (int)$f($min, $max) : random_int($min, $max);
}

// Слово при числе: формы для 1, 2 и 5 — cm_plural(5, ['победа', 'победы', 'побед']) → «побед».
function cm_plural(int $n, array $forms): string {
    $n = abs($n) % 100;
    if ($n >= 11 && $n <= 19) { return $forms[2]; }
    $d = $n % 10;
    if ($d === 1) { return $forms[0]; }
    return ($d >= 2 && $d <= 4) ? $forms[1] : $forms[2];
}

// --------------------------------------------------------------------------
//  Случайные команды. Ответы временные, кроме «!кто».
// --------------------------------------------------------------------------

// «рандом» — от 1 до 100, «рандом 50» — от 1 до 50, «рандом 10 20» — от 10 до 20.
function cm_cmd_rand(array $ctx, array $cmd): void {
    if (!preg_match('~^(-?[0-9]{1,10})?(?:\s+(-?[0-9]{1,10}))?$~', trim($cmd['args']), $m)) {
        cm_reply($ctx, cm_t('rand_usage'));
        return;
    }
    $one = isset($m[1]) && $m[1] !== '';
    $two = isset($m[2]) && $m[2] !== '';
    $x = $two ? (int)$m[1] : 1;
    $y = $two ? (int)$m[2] : ($one ? (int)$m[1] : 100);
    $lo = min($x, $y);
    $hi = max($x, $y);
    if (max(abs($lo), abs($hi)) > CM_RAND_MAX) {
        cm_reply($ctx, cm_t('rand_usage'));
        return;
    }
    cm_reply($ctx, cm_t('rand_done', ['a' => cm_num($lo), 'b' => cm_num($hi), 'n' => cm_num(cm_rand($ctx, $lo, $hi))]));
}

function cm_cmd_coin(array $ctx, array $cmd): void {
    cm_reply($ctx, cm_t(cm_rand($ctx, 0, 1) === 0 ? 'coin_heads' : 'coin_tails'));
}

// «чай, кофе или сок?» → ['чай', 'кофе', 'сок'].
function cm_choose_options(string $s): array {
    $s = trim((string)preg_replace('~[?!.]+$~u', '', trim($s)));
    $out = [];
    foreach (preg_split('~\s*,\s*|\s+или\s+~iu', $s) as $p) {
        $p = trim((string)$p);
        if ($p !== '') { $out[] = $p; }
    }
    return $out;
}

function cm_cmd_choose(array $ctx, array $cmd): void {
    $opts = cm_choose_options($cmd['args']);
    if (count($opts) < 2 || count($opts) > CM_CHOOSE_MAX) {
        cm_reply($ctx, cm_t('choose_usage'));
        return;
    }
    cm_reply($ctx, cm_t('choose_done', ['pick' => $opts[cm_rand($ctx, 0, count($opts) - 1)]]));
}

// Вероятность 0–100. Тот же текст в тот же день по Москве — то же число:
// иначе её перекидывали бы до нужного.
function cm_chance(string $text, int $now): int {
    $key = cm_norm(trim((string)preg_replace('~\s+~u', ' ', $text)));
    return crc32($key . '|' . cm_day($now)) % 101;
}

function cm_cmd_chance(array $ctx, array $cmd): void {
    $text = trim($cmd['args'] . ' ' . $cmd['body']);
    if ($text === '') {
        cm_reply($ctx, cm_t('chance_usage'));
        return;
    }
    cm_reply($ctx, cm_t('chance_done', ['n' => cm_chance($text, (int)$ctx['now'])]));
}

function cm_cmd_ball(array $ctx, array $cmd): void {
    if (trim($cmd['args'] . $cmd['body']) === '') {
        cm_reply($ctx, cm_t('ball_usage'));
        return;
    }
    cm_reply($ctx, cm_t('ball_done', ['answer' => CM_BALL[cm_rand($ctx, 0, count(CM_BALL) - 1)]]));
}

// «!кто дежурит сегодня?» — случайный участник из писавших за неделю (ботов
// счётчик не считает, ушедших cm_stats_top не отдаёт). Только с префиксом
// (CM_PREFIX_ONLY в parse.php): «кто знает…» в обычной речи — не команда.
// Ответ постоянный: ради него и спрашивали.
function cm_cmd_who(array $ctx, array $cmd): void {
    if (!cm_need2($ctx)) { return; }
    $pdo = $ctx['pdo'];
    $top = cm_stats_top($pdo, (int)$ctx['chat']['chat_id'], CM_WHO_DAYS, (int)$ctx['now'], 1000);
    if (!$top) {
        cm_reply($ctx, cm_t('who_empty'));
        return;
    }
    $name = cm_user_name($pdo, $top[cm_rand($ctx, 0, count($top) - 1)]['user_id']);
    $q = trim((string)preg_replace('~[?]+$~u', '', trim($cmd['args'])));
    cm_reply($ctx, $q === '' ? cm_t('who_any', ['name' => $name]) : cm_t('who_done', ['q' => $q, 'name' => $name]), false);
}
```

- [ ] **Step 6: Правки файлов этапов 1–2**

Edit `public_html/api/lib/cm/db.php` — после строки

```php
const CM_CHAT_TABLES2_ROWS = ['cm_rep_log', 'cm_awards'];
```

добавить

```php
// Таблицы этапа 3 — так же, если выполнена его миграция. У cm_clans имя
// уникально в чате, поэтому она уступает место, как таблицы с ключом по чату.
const CM_CHAT_TABLES3_PK   = ['cm_game_stats', 'cm_clans', 'cm_clan_members'];
const CM_CHAT_TABLES3_ROWS = ['cm_offers', 'cm_games', 'cm_couples', 'cm_marriages'];
```

Edit `public_html/api/lib/cm/db.php`, в `cm_migrate()` — фрагмент

```php
        $rows = array_merge($rows, CM_CHAT_TABLES2_ROWS);
    }
```

заменить на

```php
        $rows = array_merge($rows, CM_CHAT_TABLES2_ROWS);
    }
    if (cm_ready3($pdo)) {
        $pk = array_merge($pk, CM_CHAT_TABLES3_PK);
        $rows = array_merge($rows, CM_CHAT_TABLES3_ROWS);
    }
```

Edit `public_html/api/lib/cm/parse.php` — перед строкой

```php
    'помощь'             => ['help', null, 0, true, false],
```

вставить

```php
    'рандом'             => ['rand', 'игры', 0, false, false],
    'монетка'            => ['coin', 'игры', 0, true, false],
    'выбери'             => ['choose', 'игры', 0, false, false],
    'инфа'               => ['chance', 'игры', 0, false, false],
    'шанс'               => ['chance', 'игры', 0, false, false],
    'шар'                => ['ball', 'игры', 0, false, false],
    'кто'                => ['who', 'игры', 0, false, false],
```

Edit `public_html/api/lib/cm/parse.php`, конец `CM_BARE_ARGS` — фрагмент

```php
    'profile_of'  => '~^$~',
];
```

заменить на

```php
    'profile_of'  => '~^$~',
    'rand'        => '~^(?:-?[0-9]{1,10}(?: +-?[0-9]{1,10})?)?$~',
    'choose'      => '~\sили\s|,~u',
    'chance'      => '~\S~u',
    'ball'        => '~\S~u',
];

// Команды только с префиксом: без него это слово обычной речи («кто знает…»).
// Проверяет первая строка cm_run_command (router.php).
const CM_PREFIX_ONLY = ['who'];
```

Edit `public_html/api/lib/cm/router.php` — фрагмент

```php
 *    цели подходит под её шаблон (cm_bare_ok, parse.php).
 */
function cm_run_command(array $ctx, array $cmd): bool {
    if (!$cmd['prefixed'] && !cm_bare_ok($ctx, $cmd)) { return false; }
```

заменить на

```php
 *    цели подходит под её шаблон (cm_bare_ok, parse.php);
 *  - команда из CM_PREFIX_ONLY без префикса — не команда («!кто»).
 */
function cm_run_command(array $ctx, array $cmd): bool {
    if (!$cmd['prefixed'] && in_array($cmd['handler'], CM_PREFIX_ONLY, true)) { return false; }
    if (!$cmd['prefixed'] && !cm_bare_ok($ctx, $cmd)) { return false; }
```

Edit `public_html/api/lib/cm/ranks.php`: строку `                     'репутация', 'награды', 'анкета'];` заменить на `                     'репутация', 'награды', 'анкета', 'игры'];`.

Edit `public_html/api/lib/cm/texts.php` — после строки

```php
    'profile_about'    => 'О себе: {about}',
```

добавить

```php
    // этап 3: случайные команды
    'rand_usage'       => 'Так: «рандом», «рандом 100» или «рандом 1 100» — числа до миллиарда.',
    'rand_done'        => '🎲 Число от {a} до {b}: {n}',
    'coin_heads'       => '🪙 Орёл!',
    'coin_tails'       => '🪙 Решка!',
    'choose_usage'     => 'Так: «выбери чай или кофе» — от 2 до 10 вариантов через «или» или запятую.',
    'choose_done'      => '🤔 Выбираю: {pick}',
    'chance_usage'     => 'Так: «инфа завтра будет дождь».',
    'chance_done'      => '🔮 Вероятность: {n}%',
    'ball_usage'       => 'Так: «шар сдам экзамен?»',
    'ball_done'        => '🎱 {answer}',
    'who_done'         => '🎯 Кто {q}? Думаю, {name}.',
    'who_any'          => '🎯 Думаю, {name}.',
    'who_empty'        => 'За неделю здесь никто не писал.',
```

Edit `public_html/api/lib/cm/cm.php`: строку `          'router', 'cron'] as $cm_part) {` заменить на `          'fun', 'router', 'cron'] as $cm_part) {`.

- [ ] **Step 7: Тест проходит, этапы 1–2 целы**

Run: `/c/xampp/php/php.exe tests/cm_fun_test.php`
Expected: все `ok - …`, в конце `82 assertions, 0 failures`.

Run:
```bash
for t in tests/cm_*_test.php tests/php74_compat_test.php; do /c/xampp/php/php.exe -d extension=gd "$t" > /dev/null 2>&1 || echo "FAIL $t"; done; echo done
```
Expected: одна строка `done`.

- [ ] **Step 8: Commit**

```bash
git add docs/migrations/2026-11-08-chat-manager-games.sql tests/cm_schema.php tests/cm_helpers.php tests/cm_fun_test.php public_html/api/lib/cm/fun.php public_html/api/lib/cm/db.php public_html/api/lib/cm/parse.php public_html/api/lib/cm/router.php public_html/api/lib/cm/ranks.php public_html/api/lib/cm/texts.php public_html/api/lib/cm/cm.php
git commit -m "feat(cm): таблицы этапа 3 и случайные команды" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git show --stat HEAD
```
Expected: 11 файлов, ни одной строки `Bin`.

---

### Task 2: РП-действия и опыт пары

**Files:**
- Modify: `public_html/api/lib/cm/fun.php` (раздел РП в конце файла)
- Modify: `public_html/api/lib/cm/parse.php`, `ranks.php`, `texts.php`
- Test: `tests/cm_fun_test.php` (новые тесты)

**Interfaces:**
- Consumes: `cm_need3` (Task 1); этап 2: `cm_profile_get(PDO $pdo, int $userId): array` (`gender` — `''`/`'m'`/`'f'`), `cm_profile_put(PDO $pdo, int $userId, string $field, string $value, int $now): void` (в тестах), `cm_day`, `CM_TG_SERVICE_ID` (777000, rep.php); этап 1: `cm_need_target(array $ctx, string &$args): ?array`, `cm_display_name`.
- Produces:
  - `CM_RP` (фраза → `[смайл, «он», «она», пол не указан]`, 14 фраз), `CM_RP_BOT_OK` (`обнять`, `похвалить`), `CM_RP_XP_DAY` (20)
  - `cm_botlike(array $t): bool` — бот или служебный Telegram 777000 (берут Task 3–6)
  - `cm_couple_xp(PDO $pdo, int $chatId, int $a, int $b, int $now): void` — +1 опыт паре, не больше 20 в сутки по Москве
  - обработчик `cm_cmd_rp`; раздел `рп`; текст `rp_done`

- [ ] **Step 1: Падающие тесты**

Edit `tests/cm_fun_test.php` — перед строкой

```php
run_tests();
```

вставить

```php
// Ответ на сообщение человека $to (по умолчанию Петя, id 20).
function fn_reply(int $to = 20, string $name = 'Петя', array $from = []): array {
    return ['reply_to_message' => ['message_id' => 70, 'from' => $from + ['id' => $to, 'is_bot' => false, 'first_name' => $name]]];
}

test('РП: ответом и @ником, форма глагола по полу из анкеты; ответ постоянный', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 20, 'petya', 'Петя');
    cm_do(cm_ctx_for($pdo, $log, 7, '', fn_reply()), 'обнять');
    assert_eq('🤗 U7 обнял(а) Петя', cm_last_text($log));
    cm_profile_put($pdo, 7, 'gender', 'f', CM_NOW);
    cm_do(cm_ctx_for($pdo, $log, 7), 'пожать руку @petya');
    assert_eq('🤝 U7 пожала руку Петя', cm_last_text($log));
    cm_profile_put($pdo, 7, 'gender', 'm', CM_NOW);
    cm_do(cm_ctx_for($pdo, $log, 7, '', fn_reply()), 'убить');
    assert_eq('🔪 U7 убил Петя', cm_last_text($log));
    assert_eq(0, (int)$pdo->query('SELECT COUNT(*) FROM cm_queue')->fetchColumn(), 'не удаляется');
    assert_eq(14, count(CM_RP));
    foreach (array_keys(CM_RP) as $p) { assert_eq('rp', CM_COMMANDS[$p][0], $p); }
});

test('РП: себе нельзя, ботам — только обнять и похвалить, без цели — подсказка', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_do(cm_ctx_for($pdo, $log, 7, '', fn_reply(7, 'U7')), 'обнять');
    assert_eq(cm_t('target_self'), cm_last_text($log));
    $bot = fn_reply(555, 'Бот', ['is_bot' => true]);
    cm_do(cm_ctx_for($pdo, $log, 7, '', $bot), 'ударить');
    assert_eq(cm_t('target_bot'), cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, 7, '', $bot), 'похвалить');
    assert_eq('👏 U7 похвалил(а) Бот', cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, 7, '', fn_reply(777000, 'Telegram')), 'поцеловать');
    assert_eq(cm_t('target_bot'), cm_last_text($log), 'служебный Telegram — как бот');
    cm_do(cm_ctx_for($pdo, $log, 7), 'обнять');
    assert_eq(cm_t('need_target'), cm_last_text($log));
});

test('опыт пары: +1 за РП друг другу, не больше 20 в сутки по Москве', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $pdo->exec('INSERT INTO cm_couples (chat_id, a_id, b_id, since, xp, xp_day, xp_today, ended_at) VALUES (' . CM_CHAT . ', 20, 7, 0, 0, 0, 0, 0)');
    $xp = function () use ($pdo): array {
        return array_map('intval', $pdo->query('SELECT xp, xp_day, xp_today FROM cm_couples')->fetch(PDO::FETCH_NUM));
    };
    for ($i = 0; $i < 25; $i++) { cm_do(cm_ctx_for($pdo, $log, 7, '', fn_reply(), [], CM_T2359), 'обнять'); }
    assert_eq([20, 20260921, 20], $xp());
    cm_do(cm_ctx_for($pdo, $log, 20, '', fn_reply(7, 'U7'), [], CM_T0001), 'погладить');
    assert_eq([21, 20260922, 1], $xp(), 'новые сутки, и в обе стороны');
    cm_do(cm_ctx_for($pdo, $log, 7, '', fn_reply(30, 'Коля'), [], CM_T0001), 'обнять');
    assert_eq([21, 20260922, 1], $xp(), 'не пара — без опыта');
    $pdo->exec('UPDATE cm_couples SET ended_at = 1');
    cm_do(cm_ctx_for($pdo, $log, 7, '', fn_reply(), [], CM_T0001), 'обнять');
    assert_eq(21, $xp()[0], 'расставшимся — без опыта');
});

test('РП без префикса: ровно команда с целью, лишние слова — речь', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 20, 'petya', 'Петя');
    assert_eq(true, fn_run($pdo, $log, 7, 'обнять', fn_reply()));
    assert_eq(true, fn_run($pdo, $log, 7, 'обнять @petya'));
    $n = count(cm_calls($log, 'sendMessage'));
    assert_eq(false, fn_run($pdo, $log, 7, 'обнять'));
    assert_eq(false, fn_run($pdo, $log, 7, 'ударить бы его', fn_reply()));
    assert_eq(false, fn_run($pdo, $log, 7, 'убить время'));
    assert_eq($n, count(cm_calls($log, 'sendMessage')));
});

test('дк рп 6 выключает РП; без таблиц этапа 3 — «ещё не настроено»', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), 'дк рп 6');
    assert_eq(true, fn_run($pdo, $log, 7, '!обнять', fn_reply()));
    assert_eq(cm_t('cmd_off'), cm_last_text($log));
    $pdo = cm_test_pdo();
    $pdo->exec('DROP TABLE cm_offers');
    cm_do(cm_ctx_for($pdo, $log, 7, '', fn_reply()), 'обнять');
    assert_eq(cm_t('not_ready2'), cm_last_text($log));
});

```

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `/c/xampp/php/php.exe tests/cm_fun_test.php`
Expected: FAIL — `ERROR - РП: ответом и @ником, …: не команда: обнять` и ещё четыре `ERROR` в новых тестах; тесты Task 1 — `ok`.

- [ ] **Step 3: РП**

Edit `public_html/api/lib/cm/fun.php` — после строк

```php
    cm_reply($ctx, $q === '' ? cm_t('who_any', ['name' => $name]) : cm_t('who_done', ['q' => $q, 'name' => $name]), false);
}
```

добавить

```php

// --------------------------------------------------------------------------
//  РП-действия: «обнять» ответом на сообщение или с @ником
// --------------------------------------------------------------------------

// Фраза → [смайл, «он», «она», пол не указан]. Имена не склоняются.
const CM_RP = [
    'обнять'      => ['🤗', 'обнял', 'обняла', 'обнял(а)'],
    'поцеловать'  => ['😘', 'поцеловал', 'поцеловала', 'поцеловал(а)'],
    'погладить'   => ['🥰', 'погладил', 'погладила', 'погладил(а)'],
    'ударить'     => ['👊', 'ударил', 'ударила', 'ударил(а)'],
    'укусить'     => ['😬', 'укусил', 'укусила', 'укусил(а)'],
    'пнуть'       => ['🦶', 'пнул', 'пнула', 'пнул(а)'],
    'пожать руку' => ['🤝', 'пожал руку', 'пожала руку', 'пожал(а) руку'],
    'похвалить'   => ['👏', 'похвалил', 'похвалила', 'похвалил(а)'],
    'поздравить'  => ['🎉', 'поздравил', 'поздравила', 'поздравил(а)'],
    'накормить'   => ['🍲', 'накормил', 'накормила', 'накормил(а)'],
    'напоить'     => ['🥤', 'напоил', 'напоила', 'напоил(а)'],
    'пощекотать'  => ['🤭', 'пощекотал', 'пощекотала', 'пощекотал(а)'],
    'ущипнуть'    => ['🤏', 'ущипнул', 'ущипнула', 'ущипнул(а)'],
    'убить'       => ['🔪', 'убил', 'убила', 'убил(а)'],
];
// С ботом — только это, остальное с ним бессмысленно.
const CM_RP_BOT_OK = ['обнять', 'похвалить'];
// Опыта паре от РП — не больше стольких в сутки по Москве.
const CM_RP_XP_DAY = 20;

// Бот или служебный аккаунт Telegram (от него приходят автопересылки из канала).
function cm_botlike(array $t): bool {
    return !empty($t['is_bot']) || (int)($t['id'] ?? 0) === CM_TG_SERVICE_ID;
}

// +1 опыт паре $a и $b (в любом порядке), не больше CM_RP_XP_DAY в сутки по
// Москве: новый день обнуляет счёт сегодняшнего. Не пара — ничего.
function cm_couple_xp(PDO $pdo, int $chatId, int $a, int $b, int $now): void {
    $st = $pdo->prepare('SELECT id FROM cm_couples WHERE chat_id = :c AND ended_at = 0
        AND ((a_id = :a AND b_id = :b) OR (a_id = :b2 AND b_id = :a2))');
    $st->execute([':c' => $chatId, ':a' => $a, ':b' => $b, ':a2' => $a, ':b2' => $b]);
    $id = (int)$st->fetchColumn();
    if ($id === 0) { return; }
    $day = cm_day($now);
    $pdo->prepare('UPDATE cm_couples SET xp_day = :d, xp_today = 0 WHERE id = :id AND xp_day <> :d2')
        ->execute([':d' => $day, ':id' => $id, ':d2' => $day]);
    $pdo->prepare('UPDATE cm_couples SET xp = xp + 1, xp_today = xp_today + 1 WHERE id = :id AND xp_today < ' . CM_RP_XP_DAY)
        ->execute([':id' => $id]);
}

// «🤗 Вася обнял(а) Петя». Форма глагола — по полу из анкеты автора (этап 2).
// Ответ постоянный: это и есть разговор в чате.
function cm_cmd_rp(array $ctx, array $cmd): void {
    if (!cm_need3($ctx)) { return; }
    $args = $cmd['args'];
    $t = cm_need_target($ctx, $args);
    if ($t === null) { return; }
    $me = (int)$ctx['actor'];
    if ((int)$t['id'] === $me) {
        cm_reply($ctx, cm_t('target_self'));
        return;
    }
    $phrase = $cmd['phrase'];
    $bot = cm_botlike($t);
    if ($bot && !in_array($phrase, CM_RP_BOT_OK, true)) {
        cm_reply($ctx, cm_t('target_bot'));
        return;
    }
    $rp = CM_RP[$phrase];
    $g = cm_profile_get($ctx['pdo'], $me)['gender'];
    $from = is_array($ctx['msg']['from'] ?? null) ? $ctx['msg']['from'] : [];
    cm_reply($ctx, cm_t('rp_done', ['emoji' => $rp[0], 'from' => cm_display_name($from),
        'verb' => $rp[$g === 'm' ? 1 : ($g === 'f' ? 2 : 3)], 'to' => $t['name']]), false);
    if (!$bot) { cm_couple_xp($ctx['pdo'], (int)$ctx['chat']['chat_id'], $me, (int)$t['id'], (int)$ctx['now']); }
}
```

- [ ] **Step 4: Команды, раздел, текст**

Edit `public_html/api/lib/cm/parse.php` — перед строкой

```php
    'помощь'             => ['help', null, 0, true, false],
```

вставить

```php
    'обнять'             => ['rp', 'рп', 0, false, true],
    'поцеловать'         => ['rp', 'рп', 0, false, true],
    'погладить'          => ['rp', 'рп', 0, false, true],
    'ударить'            => ['rp', 'рп', 0, false, true],
    'укусить'            => ['rp', 'рп', 0, false, true],
    'пнуть'              => ['rp', 'рп', 0, false, true],
    'пожать руку'        => ['rp', 'рп', 0, false, true],
    'похвалить'          => ['rp', 'рп', 0, false, true],
    'поздравить'         => ['rp', 'рп', 0, false, true],
    'накормить'          => ['rp', 'рп', 0, false, true],
    'напоить'            => ['rp', 'рп', 0, false, true],
    'пощекотать'         => ['rp', 'рп', 0, false, true],
    'ущипнуть'           => ['rp', 'рп', 0, false, true],
    'убить'              => ['rp', 'рп', 0, false, true],
```

Edit `public_html/api/lib/cm/parse.php`, в `CM_BARE_ARGS` — фрагмент

```php
    'ball'        => '~\S~u',
];
```

заменить на

```php
    'ball'        => '~\S~u',
    'rp'          => '~^$~',
];
```

Edit `public_html/api/lib/cm/ranks.php`: строку `                     'репутация', 'награды', 'анкета', 'игры'];` заменить на `                     'репутация', 'награды', 'анкета', 'игры', 'рп'];`.

Edit `public_html/api/lib/cm/texts.php` — после строки

```php
    'who_empty'        => 'За неделю здесь никто не писал.',
```

добавить

```php
    // РП
    'rp_done'          => '{emoji} {from} {verb} {to}',
```

- [ ] **Step 5: Тесты проходят, остальное цело**

Run: `/c/xampp/php/php.exe tests/cm_fun_test.php`
Expected: `119 assertions, 0 failures`.

Run:
```bash
for t in tests/cm_*_test.php tests/php74_compat_test.php; do /c/xampp/php/php.exe -d extension=gd "$t" > /dev/null 2>&1 || echo "FAIL $t"; done; echo done
```
Expected: одна строка `done`.

- [ ] **Step 6: Commit**

```bash
git add public_html/api/lib/cm/fun.php tests/cm_fun_test.php public_html/api/lib/cm/parse.php public_html/api/lib/cm/ranks.php public_html/api/lib/cm/texts.php
git commit -m "feat(cm): РП-действия и опыт пары" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git show --stat HEAD
```
Expected: 5 файлов, без `Bin`.

---

### Task 3: Приглашения, кубы и исход проигравшему

**Files:**
- Create: `public_html/api/lib/cm/offers.php`, `public_html/api/lib/cm/dice.php`
- Modify: `public_html/api/lib/cm/db.php` (`CM_DEFAULTS`), `parse.php`, `router.php` (`cm_on_callback`), `cron.php` (`cm_cron_tick`), `texts.php`, `cm.php`
- Modify: `tests/cm_helpers.php` (`cm_press`), `tests/cm_router_test.php` (кнопка через вебхук, ключ `games` у cron)
- Test: `tests/cm_offers_test.php`, `tests/cm_dice_test.php`

**Interfaces:**
- Consumes: `cm_ready3`, `cm_need3`, `cm_plural` (Task 1), `cm_botlike` (Task 2); этап 1: `cm_target`, `cm_reply(array $ctx, string $text, bool $temp = true, array $o = []): int` (`$o['buttons']` — inline_keyboard; 0 — бюджет кончился), `cm_budget_take(PDO $pdo, int $chatId, int $now): bool`, `cm_ctx`, `cm_chat_row`, `cm_admins_refresh`, `cm_exempt(array $ctx, int $userId): bool` (ранг ≥ 1 или админ Telegram), `cm_parse_actions(string $s, bool $forWarns): ?array`, `cm_actions_text(array $actions): string`, `cm_apply_actions(array $ctx, int $userId, int $msgId, array $actions, string $reason, int $byId): string`, `cm_settings_save`, `cm_user_name`, `cm_display_name`; тексты этапа 1 `btn_accept`, `not_yours`, `offer_stale` («Предложение уже неактуально.»).
- Produces:
  - `CM_OFFER_TTL` (вид → секунд: duel/dice/clan 300, couple/marry/remarry 600), `CM_OUTCOME_GAMES`, `CM_GAME_FIELDS`
  - `cm_offer_get(PDO $pdo, int $id): ?array` — строка `cm_offers` (`id, chat_id, kind, from_id, to_id, ref_id, msg_id, expires_at, status`), числа — `int`
  - `cm_offer_close(PDO $pdo, int $id, string $status): bool`
  - `cm_edit(array $ctx, int $msgId, string $text, array $buttons = []): void` — editMessageText в чате контекста
  - `cm_offer_send(array $ctx, string $kind, int $toId, int $refId, string $text): int` — id или 0
  - `cm_offer_callback(array $ctx, array $q, int $id, string $ans): string`; вид приглашения подключается функцией `cm_offer_yes_<kind>(array $ctx, array $offer): string` ('' — вышло, иначе ключ текста)
  - `cm_tick_ctx(PDO $pdo, array $cfg, callable $send, int $chatId, int $now): ?array`, `cm_games_tick(PDO $pdo, array $cfg, callable $send, int $now): int`
  - `cm_game_invite(array $ctx, string $kind, array $t, string $textKey, string $outcomeKey): void`
  - `cm_game_score(PDO $pdo, int $chatId, int $userId, string $kind, string $field): void`, `cm_game_stat(PDO $pdo, int $chatId, int $userId, string $kind): array` (`['wins', 'losses', 'draws']`), `cm_score_text(array $s, bool $draws): string`, `cm_game_top(array $ctx, string $kind, string $head, string $empty, bool $draws): void`
  - `cm_game_stake(array $ctx, string $key, int $a, int $b): array`, `cm_stake_line(…): string`, `cm_game_outcome(array $ctx, string $key, int $a, int $b, int $loser, string $reason): string`, `cm_outcome_cmd(array $ctx, array $cmd, string $key): void`
  - `cm_dice_throw(array $ctx, int $replyTo): int`, `cm_offer_yes_dice`; обработчики `cm_cmd_dice`, `cm_cmd_dice_outcome`
  - `CM_DEFAULTS`: `duel_outcome`, `dice_outcome` (по умолчанию `[]`)
  - роутер: кнопка `cm:p:<id>:y|n` → `cm_offer_callback`; `cm_cron_tick` → ключ `games`
  - Тексты: `btn_decline`, `offer_accepted`, `offer_declined`, `offer_expired`, `offer_replaced`, `game_stake`, `game_won`, `game_outcome`, `game_top_line`, `outcome_usage`, `outcome_set`, `outcome_off`, `reason_dice`, `dice_offer`, `dice_result`, `dice_draw`, `dice_failed`, `dice_top_head`, `dice_top_empty`
  - Тесты: `cm_press(PDO $pdo, array &$log, int $who, string $data, array $answers = [], int $now = CM_NOW, ?callable $rand = null): string` — нажатие кнопки `cm:p:…` / `cm:d:…`, ответ на кнопку

- [ ] **Step 1: Падающие тесты**

Edit `tests/cm_helpers.php` — после строк

```php
        if ($v < $min || $v > $max) { throw new RuntimeException("случайное $v вне [$min, $max]"); }
        return $v;
    };
}
```

добавить

```php

// Нажатие кнопки этапа 3 человеком $who: $data — «cm:p:<id>:y» (приглашение)
// или «cm:d:<id>:s» (ход в дуэли). Контекст — как у cm_on_callback (чат,
// нажавший и его ранг), случайность — $rand. Возвращает ответ на кнопку.
function cm_press(PDO $pdo, array &$log, int $who, string $data, array $answers = [], int $now = CM_NOW, ?callable $rand = null): string {
    $ctx = cm_ctx_for($pdo, $log, $who, '', [], $answers, $now);
    if ($rand !== null) { $ctx['rand'] = $rand; }
    $p = explode(':', $data);
    $q = ['id' => 'q', 'from' => ['id' => $who], 'data' => $data];
    return $p[1] === 'd' ? cm_duel_callback($ctx, $q, (int)$p[2], (string)$p[3])
        : cm_offer_callback($ctx, $q, (int)$p[2], (string)$p[3]);
}
```

Write `tests/cm_offers_test.php`:

```php
<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';

// Приглашения с кнопками (этап 3): api/lib/cm/offers.php. Вид — кубы
// (dice.php): у него нет своих условий, поэтому видно само приглашение.

function of_people(PDO $pdo): void {
    cm_person($pdo, 7, 'user7', 'U7');
    cm_person($pdo, 20, 'petya', 'Петя');
    cm_person($pdo, 21, 'kolya', 'Коля');
    cm_person($pdo, 30, 'user30', 'U30');
}

function of_last(PDO $pdo): array {
    return cm_offer_get($pdo, (int)$pdo->query('SELECT MAX(id) FROM cm_offers')->fetchColumn());
}

test('приглашение: кнопки «Принять» и «Отказаться», сообщение постоянное', function () {
    $pdo = cm_test_pdo();
    $log = [];
    of_people($pdo);
    cm_do(cm_ctx_for($pdo, $log, 7), 'кубы @petya');
    $sent = cm_calls($log, 'sendMessage')[0][1];
    assert_eq('🎲 Петя, U7 предлагает бросить кубы!', $sent['text']);
    assert_eq([[['text' => 'Принять', 'callback_data' => 'cm:p:1:y'], ['text' => 'Отказаться', 'callback_data' => 'cm:p:1:n']]],
        $sent['reply_markup']['inline_keyboard']);
    $o = of_last($pdo);
    assert_eq(['dice', 7, 20, 'open', CM_NOW + 300], [$o['kind'], $o['from_id'], $o['to_id'], $o['status'], $o['expires_at']]);
    assert_eq(true, $o['msg_id'] > 5000, 'запомнили сообщение с кнопками');
    assert_eq(0, (int)$pdo->query('SELECT COUNT(*) FROM cm_queue')->fetchColumn(), 'не удаляется');
});

test('чужое нажатие — «не для вас», приглашение ждёт', function () {
    $pdo = cm_test_pdo();
    $log = [];
    of_people($pdo);
    cm_do(cm_ctx_for($pdo, $log, 7), 'кубы @petya');
    assert_eq(cm_t('not_yours'), cm_press($pdo, $log, 30, 'cm:p:1:y'));
    assert_eq(cm_t('not_yours'), cm_press($pdo, $log, 7, 'cm:p:1:n'), 'и автору тоже');
    assert_eq('open', of_last($pdo)['status']);
    assert_eq([], cm_calls($log, 'editMessageText'));
    assert_eq('', cm_press($pdo, $log, 20, 'cm:p:1:z'), 'неизвестная буква');
});

test('отказ: сообщение правится без кнопок, повтор — «уже неактуально»', function () {
    $pdo = cm_test_pdo();
    $log = [];
    of_people($pdo);
    cm_do(cm_ctx_for($pdo, $log, 7), 'кубы @petya');
    $mid = of_last($pdo)['msg_id'];
    assert_eq('Петя отказывается.', cm_press($pdo, $log, 20, 'cm:p:1:n'));
    $e = cm_calls($log, 'editMessageText')[0][1];
    assert_eq([$mid, 'Петя отказывается.', false], [$e['message_id'], $e['text'], isset($e['reply_markup'])]);
    assert_eq('no', of_last($pdo)['status']);
    assert_eq(cm_t('offer_stale'), cm_press($pdo, $log, 20, 'cm:p:1:y'));
    assert_eq(1, count(cm_calls($log, 'editMessageText')), 'устаревшая кнопка — без сообщений в чат');
    assert_eq(1, count(cm_calls($log, 'sendMessage')));
});

test('срок: через 5 минут кнопка неактуальна, cron закрывает остальные', function () {
    $pdo = cm_test_pdo();
    $log = [];
    of_people($pdo);
    cm_do(cm_ctx_for($pdo, $log, 7), 'кубы @petya');
    assert_eq(cm_t('offer_stale'), cm_press($pdo, $log, 20, 'cm:p:1:y', [], CM_NOW + 300));
    assert_eq('gone', cm_offer_get($pdo, 1)['status']);
    assert_eq(cm_t('offer_expired'), cm_calls($log, 'editMessageText')[0][1]['text']);
    cm_do(cm_ctx_for($pdo, $log, 21), 'кубы @petya');
    assert_eq(0, cm_games_tick($pdo, cm_cfg(), cm_rec($log), CM_NOW + 299));
    assert_eq(1, cm_games_tick($pdo, cm_cfg(), cm_rec($log), CM_NOW + 300));
    assert_eq('gone', cm_offer_get($pdo, 2)['status']);
    $e = cm_calls($log, 'editMessageText');
    assert_eq([2, cm_offer_get($pdo, 2)['msg_id'], cm_t('offer_expired')], [count($e), $e[1][1]['message_id'], $e[1][1]['text']]);
    assert_eq(0, cm_games_tick($pdo, cm_cfg(), cm_rec($log), CM_NOW + 400), 'второй раз не трогает');
});

test('новое приглашение того же вида закрывает старое, другого вида — нет', function () {
    $pdo = cm_test_pdo();
    $log = [];
    of_people($pdo);
    cm_chat_ensure($pdo, ['id' => CM_CHAT, 'title' => 'Тестовый чат'], CM_NOW);
    $pdo->exec("INSERT INTO cm_offers (chat_id, kind, from_id, to_id, ref_id, msg_id, expires_at, status)
        VALUES (" . CM_CHAT . ", 'duel', 7, 20, 0, 77, " . (CM_NOW + 300) . ", 'open')");
    cm_do(cm_ctx_for($pdo, $log, 7), 'кубы @petya');
    cm_do(cm_ctx_for($pdo, $log, 7), 'кубы @kolya');
    $st = array_map(function ($id) use ($pdo) { return cm_offer_get($pdo, $id)['status']; }, [1, 2, 3]);
    assert_eq(['open', 'gone', 'open'], $st);
    $e = cm_calls($log, 'editMessageText');
    assert_eq([1, cm_offer_get($pdo, 2)['msg_id'], cm_t('offer_replaced')], [count($e), $e[0][1]['message_id'], $e[0][1]['text']]);
    assert_eq(cm_t('offer_stale'), cm_press($pdo, $log, 20, 'cm:p:2:y'));
});

test('бюджет сообщений кончился — приглашения нет', function () {
    $pdo = cm_test_pdo();
    $log = [];
    of_people($pdo);
    $ctx = cm_ctx_for($pdo, $log, 7);
    $pdo->exec('UPDATE cm_chats SET sent_window = ' . intdiv(CM_NOW, 60) . ', sent_count = 15');
    cm_do($ctx, 'кубы @petya');
    assert_eq([], cm_calls($log, 'sendMessage'));
    assert_eq('gone', of_last($pdo)['status']);
});

test('нет таблиц этапа 3: кнопка неактуальна, cron молчит, команда — «ещё не настроено»', function () {
    $pdo = cm_test_pdo();
    $pdo->exec('DROP TABLE cm_offers');
    $log = [];
    of_people($pdo);
    assert_eq(cm_t('offer_stale'), cm_press($pdo, $log, 20, 'cm:p:1:y'));
    assert_eq(0, cm_games_tick($pdo, cm_cfg(), cm_rec($log), CM_NOW));
    cm_do(cm_ctx_for($pdo, $log, 7), 'кубы @petya');
    assert_eq(cm_t('not_ready2'), cm_last_text($log));
});

run_tests();
```

Write `tests/cm_dice_test.php`:

```php
<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';

// Кубы и исход проигравшему (этап 3): api/lib/cm/dice.php, offers.php.

function dc_people(PDO $pdo): void {
    cm_person($pdo, 7, 'user7', 'U7');
    cm_person($pdo, 20, 'petya', 'Петя');
}

// Ответы Telegram на sendDice: значения кубиков по очереди из $vals.
function dc_dice(array &$vals): array {
    return ['sendDice' => function (array $p) use (&$vals): array {
        return ['ok' => true, 'result' => ['message_id' => 7000, 'dice' => ['emoji' => '🎲', 'value' => array_shift($vals)]]];
    }];
}

test('кубы: оба броска ответом на приглашение, больше — победа, счёт', function () {
    $pdo = cm_test_pdo();
    $log = [];
    dc_people($pdo);
    cm_do(cm_ctx_for($pdo, $log, 7), 'кубы @petya');
    $mid = cm_offer_get($pdo, 1)['msg_id'];
    $vals = [5, 3];
    assert_eq(cm_t('offer_accepted'), cm_press($pdo, $log, 20, 'cm:p:1:y', dc_dice($vals)));
    $dice = cm_calls($log, 'sendDice');
    assert_eq(2, count($dice));
    assert_eq(['🎲', $mid], [$dice[0][1]['emoji'], $dice[0][1]['reply_parameters']['message_id']]);
    $e = cm_calls($log, 'editMessageText');
    assert_eq([$mid, "🎲 Кубы: U7 — 5, Петя — 3\n🏆 Победитель — U7!"], [$e[0][1]['message_id'], $e[0][1]['text']]);
    assert_eq(['wins' => 1, 'losses' => 0, 'draws' => 0], cm_game_stat($pdo, CM_CHAT, 7, 'dice'));
    assert_eq(['wins' => 0, 'losses' => 1, 'draws' => 0], cm_game_stat($pdo, CM_CHAT, 20, 'dice'));
    assert_eq('yes', cm_offer_get($pdo, 1)['status']);
    assert_eq(1, count(cm_calls($log, 'sendMessage')), 'итог — в том же сообщении');
});

test('кубы: ничья — без исхода, даже если он включён', function () {
    $pdo = cm_test_pdo();
    $log = [];
    dc_people($pdo);
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), 'кубы исход мут 10м');
    cm_do(cm_ctx_for($pdo, $log, 7), 'кубы @petya');
    $vals = [4, 4];
    cm_press($pdo, $log, 20, 'cm:p:1:y', dc_dice($vals));
    assert_eq("🎲 Кубы: U7 — 4, Петя — 4\n🤝 Ничья!", cm_calls($log, 'editMessageText')[0][1]['text']);
    assert_eq(['wins' => 0, 'losses' => 0, 'draws' => 1], cm_game_stat($pdo, CM_CHAT, 7, 'dice'));
    assert_eq(['wins' => 0, 'losses' => 0, 'draws' => 1], cm_game_stat($pdo, CM_CHAT, 20, 'dice'));
    assert_eq([], cm_calls($log, 'restrictChatMember'));
});

test('исход включён: ставка видна в приглашении, проигравшему — мут от бота', function () {
    $pdo = cm_test_pdo();
    $log = [];
    dc_people($pdo);
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), 'кубы исход мут 10м');
    assert_eq('Проигравший в кубах получит: мут 10 мин.', cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, 7), 'кубы @petya');
    assert_eq("🎲 Петя, U7 предлагает бросить кубы!\nСтавка: проигравшему — мут 10 мин.", cm_last_text($log));
    $vals = [2, 6];
    cm_press($pdo, $log, 20, 'cm:p:1:y', dc_dice($vals));
    $r = cm_calls($log, 'restrictChatMember');
    assert_eq([1, 7, CM_NOW + 600], [count($r), $r[0][1]['user_id'], $r[0][1]['until_date']]);
    assert_eq("🎲 Кубы: U7 — 2, Петя — 6\n🏆 Победитель — Петя!\n⚖️ U7: мут 10 мин", cm_calls($log, 'editMessageText')[0][1]['text']);
    $p = $pdo->query("SELECT kind, reason, by_id FROM cm_punishments")->fetch(PDO::FETCH_NUM);
    assert_eq(['mute', 'проигрыш в кубах', 0], [$p[0], $p[1], (int)$p[2]]);
});

test('исход включён, но играет модератор или админ Telegram — игра без ставки', function () {
    $pdo = cm_test_pdo();
    $log = [];
    dc_people($pdo);
    cm_lvl($pdo, 20, 1);
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), 'кубы исход мут 10м');
    cm_do(cm_ctx_for($pdo, $log, 7), 'кубы @petya');
    assert_eq('🎲 Петя, U7 предлагает бросить кубы!', cm_last_text($log), 'без строки ставки');
    $vals = [6, 1];
    cm_press($pdo, $log, 20, 'cm:p:1:y', dc_dice($vals));
    assert_eq([], cm_calls($log, 'restrictChatMember'), 'модератор проиграл — без наказания');
    assert_eq("🎲 Кубы: U7 — 6, Петя — 1\n🏆 Победитель — U7!", cm_calls($log, 'editMessageText')[0][1]['text']);
    cm_do(cm_ctx_for($pdo, $log, CM_ADMIN), 'кубы @user7');
    assert_eq('🎲 U7, U2 предлагает бросить кубы!', cm_last_text($log), 'админ Telegram — тоже без ставки');
});

test('кубы исход: показать, включить, «ничего», ошибки, ранг 3', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $o = cm_ctx_for($pdo, $log, CM_OWNER);
    cm_do($o, 'кубы исход');
    assert_eq(cm_t('outcome_off', ['game' => 'кубах']), cm_last_text($log));
    cm_do($o, 'кубы исход мут 10м, варн');
    assert_eq('Проигравший в кубах получит: мут 10 мин, варн.', cm_last_text($log));
    assert_eq([['kind' => 'mute', 'secs' => 600], ['kind' => 'warn', 'secs' => 0]],
        cm_chat_row($pdo, CM_CHAT)['settings']['dice_outcome']);
    cm_do($o, 'кубы исход');
    assert_eq('Проигравший в кубах получит: мут 10 мин, варн.', cm_last_text($log));
    foreach (['кубы исход удалить', 'кубы исход ерунда', 'кубы исход мут 400 дней'] as $bad) {
        cm_do($o, $bad);
        assert_eq(cm_t('outcome_usage'), cm_last_text($log), $bad);
    }
    cm_do($o, 'кубы исход ничего');
    assert_eq(cm_t('outcome_off', ['game' => 'кубах']), cm_last_text($log));
    assert_eq([], cm_chat_row($pdo, CM_CHAT)['settings']['dice_outcome']);
    $ctx = cm_ctx_for($pdo, $log, 7, '!кубы исход мут 1ч');
    assert_eq(true, cm_run_command($ctx, cm_parse('!кубы исход мут 1ч', CM_BOT)));
    assert_eq(cm_t('no_rank', ['need' => 3]), cm_last_text($log));
});

test('Telegram не бросил кубик — приглашение закрыто, счёта нет', function () {
    $pdo = cm_test_pdo();
    $log = [];
    dc_people($pdo);
    cm_do(cm_ctx_for($pdo, $log, 7), 'кубы @petya');
    $fail = ['sendDice' => ['ok' => false, 'description' => 'Bad Request: chat not found']];
    assert_eq(cm_t('dice_failed'), cm_press($pdo, $log, 20, 'cm:p:1:y', $fail));
    assert_eq('gone', cm_offer_get($pdo, 1)['status']);
    assert_eq(cm_t('dice_failed'), cm_calls($log, 'editMessageText')[0][1]['text']);
    assert_eq(0, (int)$pdo->query('SELECT COUNT(*) FROM cm_game_stats')->fetchColumn());
});

test('кубы без цели — топ: победы, поражения, ничьи; ушедших нет', function () {
    $pdo = cm_test_pdo();
    $log = [];
    dc_people($pdo);
    $ins = $pdo->prepare("INSERT INTO cm_game_stats (chat_id, user_id, kind, wins, losses, draws) VALUES (:c, :u, :k, :w, :l, :d)");
    foreach ([[7, 'dice', 1, 3, 0], [20, 'dice', 3, 1, 2], [21, 'dice', 9, 0, 0], [22, 'dice', 0, 5, 1], [7, 'duel', 50, 0, 0]] as $r) {
        $ins->execute([':c' => CM_CHAT, ':u' => $r[0], ':k' => $r[1], ':w' => $r[2], ':l' => $r[3], ':d' => $r[4]]);
    }
    cm_member_set($pdo, CM_CHAT, 21, ['left_at' => CM_NOW]);
    cm_do(cm_ctx_for($pdo, $log, 7), 'кубы');
    assert_eq("🎲 Лучшие в кубах:\n1. Петя — 3 победы / 1 поражение / 2 ничьи\n2. U7 — 1 победа / 3 поражения / 0 ничьих",
        cm_last_text($log));
    cm_do(cm_ctx_for(cm_test_pdo(), $log, 7), 'кубы');
    assert_eq(cm_t('dice_top_empty'), cm_last_text($log));
});

test('кубы: себя, ботов и незнакомый ник — отказ; без префикса — только «кубы» и цель', function () {
    $pdo = cm_test_pdo();
    $log = [];
    dc_people($pdo);
    $self = ['reply_to_message' => ['message_id' => 70, 'from' => ['id' => 7, 'first_name' => 'U7']]];
    cm_do(cm_ctx_for($pdo, $log, 7, '', $self), 'кубы');
    assert_eq(cm_t('target_self'), cm_last_text($log));
    $bot = ['reply_to_message' => ['message_id' => 71, 'from' => ['id' => 555, 'is_bot' => true, 'first_name' => 'Бот']]];
    cm_do(cm_ctx_for($pdo, $log, 7, '', $bot), 'кубы');
    assert_eq(cm_t('target_bot'), cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, 7), '!кубы @nobody1');
    assert_eq(cm_t('target_unknown'), cm_last_text($log));
    $run = function (string $text) use ($pdo, &$log): bool {
        return cm_run_command(cm_ctx_for($pdo, $log, 7, $text), cm_parse($text, CM_BOT));
    };
    assert_eq(true, $run('кубы @petya'));
    assert_eq(true, $run('кубы'));
    assert_eq(false, $run('кубы бросим?'));
    $pdo->exec('DROP TABLE cm_offers');
    cm_do(cm_ctx_for($pdo, $log, 7), 'кубы');
    assert_eq(cm_t('not_ready2'), cm_last_text($log));
});

run_tests();
```

Edit `tests/cm_router_test.php`, тест «cron: таймеры и очередь» — строку

```php
    assert_eq(['timers' => 0, 'deleted' => 1], cm_cron_tick($pdo, cm_cfg(), cm_rec($log), CM_NOW));
```

заменить на

```php
    assert_eq(['timers' => 0, 'deleted' => 1, 'games' => 0], cm_cron_tick($pdo, cm_cfg(), cm_rec($log), CM_NOW));
```

Edit `tests/cm_router_test.php` — перед строкой

```php
test('вебхук: правки сообщений и право удалять в группах', function () {
```

вставить

```php
test('кнопка приглашения этапа 3 идёт через вебхук', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 20, 'petya', 'Петя');
    rt_run($pdo, cm_gmsg(7, 'кубы @petya'), $log);
    $chat = ['id' => CM_CHAT, 'type' => 'supergroup', 'title' => 'Тестовый чат'];
    $q = ['id' => 'q1', 'from' => ['id' => 30, 'first_name' => 'Чужой'], 'data' => 'cm:p:1:y',
          'message' => ['message_id' => cm_offer_get($pdo, 1)['msg_id'], 'chat' => $chat, 'text' => 'приглашение']];
    cm_handle_update($pdo, cm_cfg(), cm_rec($log), ['callback_query' => $q], CM_NOW);
    $q['from'] = ['id' => 20, 'first_name' => 'Петя'];
    $q['data'] = 'cm:p:1:n';
    cm_handle_update($pdo, cm_cfg(), cm_rec($log), ['callback_query' => $q], CM_NOW);
    $ans = array_map(function ($c) { return $c[1]['text']; }, cm_calls($log, 'answerCallbackQuery'));
    assert_eq([cm_t('not_yours'), 'Петя отказывается.'], $ans);
    assert_eq('no', cm_offer_get($pdo, 1)['status']);
});

```

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `/c/xampp/php/php.exe tests/cm_offers_test.php`
Expected: FAIL — `ERROR - приглашение: …: не команда: кубы @petya`.

Run: `/c/xampp/php/php.exe tests/cm_dice_test.php`
Expected: FAIL — `ERROR - кубы: оба броска …: не команда: кубы @petya`.

- [ ] **Step 3: Приглашения и общее для игр**

Write `public_html/api/lib/cm/offers.php`:

```php
<?php
// Приглашения с кнопками и общее для игр (этап 3). Приглашают на дуэль, в
// кубы, в пару, в брак, вернуть брак и в клан. «Принять» / «Отказаться»
// (cm:p:<id>:y|n, router.php) жмёт только адресат. Срок — CM_OFFER_TTL;
// просроченные закрывает cm_games_tick() из cron. От человека в чате — одно
// открытое приглашение каждого вида: новое закрывает старое. «Принять» зовёт
// cm_offer_yes_<вид>() из файла раздела — он заново проверяет условия.
// Кнопки правят то же сообщение (editMessageText), новых не шлют: так игры не
// съедают бюджет группы (send.php).

const CM_OFFER_TTL     = ['duel' => 300, 'dice' => 300, 'couple' => 600, 'marry' => 600, 'remarry' => 600, 'clan' => 300];
// Ключ настройки исхода → «в чём» проигрыш, для текстов.
const CM_OUTCOME_GAMES = ['duel_outcome' => 'дуэли', 'dice_outcome' => 'кубах'];
const CM_GAME_FIELDS   = ['wins', 'losses', 'draws'];

function cm_offer_get(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM cm_offers WHERE id = :id');
    $st->execute([':id' => $id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) { return null; }
    foreach (['id', 'chat_id', 'from_id', 'to_id', 'ref_id', 'msg_id', 'expires_at'] as $k) { $r[$k] = (int)$r[$k]; }
    $r['kind'] = (string)$r['kind'];
    $r['status'] = (string)$r['status'];
    return $r;
}

// Закрыть открытое приглашение. false — его уже закрыли (второе нажатие, cron).
function cm_offer_close(PDO $pdo, int $id, string $status): bool {
    $st = $pdo->prepare("UPDATE cm_offers SET status = :s WHERE id = :id AND status = 'open'");
    $st->execute([':s' => $status, ':id' => $id]);
    return $st->rowCount() > 0;
}

// Поправить сообщение бота в чате контекста. Без $buttons кнопки пропадают.
function cm_edit(array $ctx, int $msgId, string $text, array $buttons = []): void {
    if ($msgId <= 0) { return; }
    $p = ['chat_id' => (int)$ctx['chat']['chat_id'], 'message_id' => $msgId, 'text' => mb_substr($text, 0, 4096),
          'link_preview_options' => ['is_disabled' => true]];
    if ($buttons) { $p['reply_markup'] = ['inline_keyboard' => $buttons]; }
    ($ctx['send'])('editMessageText', $p);
}

function cm_offer_buttons(int $id): array {
    return [[['text' => cm_t('btn_accept'), 'callback_data' => 'cm:p:' . $id . ':y'],
             ['text' => cm_t('btn_decline'), 'callback_data' => 'cm:p:' . $id . ':n']]];
}

/**
 * Приглашение от автора контекста: прежнее открытое того же вида закрывается,
 * новое уходит ответом на команду. Возвращает id или 0 — бюджет сообщений
 * группы кончился, приглашения нет.
 */
function cm_offer_send(array $ctx, string $kind, int $toId, int $refId, string $text): int {
    $pdo = $ctx['pdo'];
    $chatId = (int)$ctx['chat']['chat_id'];
    $from = (int)$ctx['actor'];
    $st = $pdo->prepare("SELECT id, msg_id FROM cm_offers WHERE chat_id = :c AND from_id = :f AND kind = :k AND status = 'open'");
    $st->execute([':c' => $chatId, ':f' => $from, ':k' => $kind]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $old) {
        if (cm_offer_close($pdo, (int)$old['id'], 'gone')) { cm_edit($ctx, (int)$old['msg_id'], cm_t('offer_replaced')); }
    }
    $pdo->prepare("INSERT INTO cm_offers (chat_id, kind, from_id, to_id, ref_id, msg_id, expires_at, status)
                   VALUES (:c, :k, :f, :t, :r, 0, :e, 'open')")
        ->execute([':c' => $chatId, ':k' => $kind, ':f' => $from, ':t' => $toId, ':r' => $refId,
                   ':e' => (int)$ctx['now'] + CM_OFFER_TTL[$kind]]);
    $id = (int)$pdo->lastInsertId();
    $mid = cm_reply($ctx, $text, false, ['buttons' => cm_offer_buttons($id)]);
    if ($mid === 0) {
        cm_offer_close($pdo, $id, 'gone');
        return 0;
    }
    $pdo->prepare('UPDATE cm_offers SET msg_id = :m WHERE id = :id')->execute([':m' => $mid, ':id' => $id]);
    return $id;
}

/**
 * Кнопка приглашения; возвращает текст для answerCallbackQuery. Чужое
 * нажатие — «не для вас»; закрытое или просроченное — «уже неактуально», без
 * сообщений в чат. «Принять» → cm_offer_yes_<вид>($ctx, $offer): '' — вышло
 * (сообщение он правит сам), иначе ключ текста: приглашение закрывается им.
 */
function cm_offer_callback(array $ctx, array $q, int $id, string $ans): string {
    if ($ans !== 'y' && $ans !== 'n') { return ''; }
    $pdo = $ctx['pdo'];
    $o = cm_ready3($pdo) ? cm_offer_get($pdo, $id) : null;
    if ($o === null || $o['chat_id'] !== (int)$ctx['chat']['chat_id'] || $o['status'] !== 'open') { return cm_t('offer_stale'); }
    if ((int)$ctx['actor'] !== $o['to_id']) { return cm_t('not_yours'); }
    if ($o['expires_at'] <= (int)$ctx['now']) {
        if (cm_offer_close($pdo, $id, 'gone')) { cm_edit($ctx, $o['msg_id'], cm_t('offer_expired')); }
        return cm_t('offer_stale');
    }
    if ($ans === 'n') {
        if (!cm_offer_close($pdo, $id, 'no')) { return cm_t('offer_stale'); }
        $text = cm_t('offer_declined', ['name' => cm_user_name($pdo, $o['to_id'])]);
        cm_edit($ctx, $o['msg_id'], $text);
        return $text;
    }
    $fn = 'cm_offer_yes_' . $o['kind'];
    if (!function_exists($fn) || !cm_offer_close($pdo, $id, 'yes')) { return cm_t('offer_stale'); }
    $err = $fn($ctx, $o);
    if ($err === '') { return cm_t('offer_accepted'); }
    $pdo->prepare("UPDATE cm_offers SET status = 'gone' WHERE id = :id")->execute([':id' => $id]);
    cm_edit($ctx, $o['msg_id'], cm_t($err));
    return cm_t($err);
}

// Контекст для cron в чате $chatId — от имени бота. null — бота в чате нет.
function cm_tick_ctx(PDO $pdo, array $cfg, callable $send, int $chatId, int $now): ?array {
    $chat = cm_chat_row($pdo, $chatId);
    if ($chat === null || $chat['active'] !== 1) { return null; }
    $msg = ['message_id' => 0, 'chat' => ['id' => $chatId, 'type' => 'supergroup', 'title' => $chat['title']]];
    $ctx = cm_ctx($pdo, $cfg, $send, $chat, $msg, $now);
    cm_admins_refresh($ctx);
    return $ctx;
}

// Раз в минуту (cron.php): закрыть просроченные приглашения. Сколько закрыто.
function cm_games_tick(PDO $pdo, array $cfg, callable $send, int $now): int {
    if (!cm_ready3($pdo)) { return 0; }
    $st = $pdo->prepare("SELECT id, chat_id, msg_id FROM cm_offers WHERE status = 'open' AND expires_at <= :n ORDER BY id LIMIT 100");
    $st->execute([':n' => $now]);
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $o) {
        if (!cm_offer_close($pdo, (int)$o['id'], 'gone')) { continue; }
        $ctx = cm_tick_ctx($pdo, $cfg, $send, (int)$o['chat_id'], $now);
        if ($ctx !== null) { cm_edit($ctx, (int)$o['msg_id'], cm_t('offer_expired')); }
        $n++;
    }
    return $n;
}

// --------------------------------------------------------------------------
//  Игры: вызов, счёт, ставка, исход
// --------------------------------------------------------------------------

// Вызов на дуэль или в кубы. Себя и ботов нельзя.
function cm_game_invite(array $ctx, string $kind, array $t, string $textKey, string $outcomeKey): void {
    if (cm_botlike($t)) {
        cm_reply($ctx, cm_t('target_bot'));
        return;
    }
    if ((int)$t['id'] === (int)$ctx['actor']) {
        cm_reply($ctx, cm_t('target_self'));
        return;
    }
    $from = is_array($ctx['msg']['from'] ?? null) ? $ctx['msg']['from'] : [];
    cm_offer_send($ctx, $kind, (int)$t['id'], 0, cm_t($textKey, ['to' => $t['name'], 'from' => cm_display_name($from),
        'stake' => cm_stake_line($ctx, $outcomeKey, (int)$ctx['actor'], (int)$t['id'])]));
}

// +1 к победам, поражениям или ничьим.
function cm_game_score(PDO $pdo, int $chatId, int $userId, string $kind, string $field): void {
    if (!in_array($field, CM_GAME_FIELDS, true)) { return; }
    $p = [':c' => $chatId, ':u' => $userId, ':k' => $kind];
    $up = $pdo->prepare("UPDATE cm_game_stats SET $field = $field + 1 WHERE chat_id = :c AND user_id = :u AND kind = :k");
    $up->execute($p);
    if ($up->rowCount() > 0) { return; }
    try {
        $pdo->prepare('INSERT INTO cm_game_stats (chat_id, user_id, kind, wins, losses, draws) VALUES (:c, :u, :k, :w, :l, :d)')
            ->execute($p + [':w' => (int)($field === 'wins'), ':l' => (int)($field === 'losses'), ':d' => (int)($field === 'draws')]);
    } catch (PDOException $e) {
        $up->execute($p);   // параллельная игра уже завела строку
    }
}

function cm_game_stat(PDO $pdo, int $chatId, int $userId, string $kind): array {
    $st = $pdo->prepare('SELECT wins, losses, draws FROM cm_game_stats WHERE chat_id = :c AND user_id = :u AND kind = :k');
    $st->execute([':c' => $chatId, ':u' => $userId, ':k' => $kind]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return ['wins' => (int)($r['wins'] ?? 0), 'losses' => (int)($r['losses'] ?? 0), 'draws' => (int)($r['draws'] ?? 0)];
}

// «5 побед / 2 поражения», с ничьими — «… / 1 ничья».
function cm_score_text(array $s, bool $draws): string {
    $t = $s['wins'] . ' ' . cm_plural($s['wins'], ['победа', 'победы', 'побед'])
       . ' / ' . $s['losses'] . ' ' . cm_plural($s['losses'], ['поражение', 'поражения', 'поражений']);
    if ($draws) { $t .= ' / ' . $s['draws'] . ' ' . cm_plural($s['draws'], ['ничья', 'ничьи', 'ничьих']); }
    return $t;
}

// Топ-10 по победам (при равенстве — меньше поражений), без покинувших чат.
function cm_game_top(array $ctx, string $kind, string $head, string $empty, bool $draws): void {
    $pdo = $ctx['pdo'];
    $st = $pdo->prepare('SELECT g.user_id, g.wins, g.losses, g.draws FROM cm_game_stats g
        LEFT JOIN cm_members m ON m.chat_id = g.chat_id AND m.user_id = g.user_id
        WHERE g.chat_id = :c AND g.kind = :k AND g.wins > 0 AND (m.left_at IS NULL OR m.left_at = 0)
        ORDER BY g.wins DESC, g.losses, g.user_id LIMIT 10');
    $st->execute([':c' => $ctx['chat']['chat_id'], ':k' => $kind]);
    $lines = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $i => $r) {
        $s = ['wins' => (int)$r['wins'], 'losses' => (int)$r['losses'], 'draws' => (int)$r['draws']];
        $lines[] = cm_t('game_top_line', ['n' => $i + 1, 'name' => cm_user_name($pdo, (int)$r['user_id']),
            'score' => cm_score_text($s, $draws)]);
    }
    cm_reply($ctx, $lines ? cm_t($head) . "\n" . implode("\n", $lines) : cm_t($empty), false);
}

// Ставка игры $a против $b: действия исхода, если модератор их включил и
// оба — не модераторы и не админы Telegram. Игра с ними идёт без ставки для
// обоих: исход их не наказывает, а наказывать только второго нечестно.
function cm_game_stake(array $ctx, string $key, int $a, int $b): array {
    $acts = (array)($ctx['chat']['settings'][$key] ?? []);
    if (!$acts || cm_exempt($ctx, $a) || cm_exempt($ctx, $b)) { return []; }
    return $acts;
}

// «\nСтавка: проигравшему — мут 10 мин.» или ''.
function cm_stake_line(array $ctx, string $key, int $a, int $b): string {
    $acts = cm_game_stake($ctx, $key, $a, $b);
    return $acts ? cm_t('game_stake', ['actions' => cm_actions_text($acts)]) : '';
}

// Исход проигравшему от имени бота (cm_apply_actions этапа 1). '' — исхода
// нет, иначе строка «⚖️ Вася: мут 10 мин» для итога.
function cm_game_outcome(array $ctx, string $key, int $a, int $b, int $loser, string $reason): string {
    $acts = cm_game_stake($ctx, $key, $a, $b);
    if (!$acts) { return ''; }
    $done = cm_apply_actions($ctx, $loser, 0, $acts, $reason, 0);
    return $done === '' ? '' : cm_t('game_outcome', ['name' => cm_user_name($ctx['pdo'], $loser), 'done' => $done]);
}

// «кубы исход мут 10м», «дуэли исход ничего»; без слов — что сейчас.
// «удалить» не годится: удалять нечего.
function cm_outcome_cmd(array $ctx, array $cmd, string $key): void {
    $game = CM_OUTCOME_GAMES[$key];
    $a = cm_norm(trim($cmd['args']));
    $s = $ctx['chat']['settings'];
    if ($a === '') {
        $cur = (array)$s[$key];
        cm_reply($ctx, $cur ? cm_t('outcome_set', ['game' => $game, 'actions' => cm_actions_text($cur)])
            : cm_t('outcome_off', ['game' => $game]));
        return;
    }
    $acts = $a === 'ничего' ? [] : cm_parse_actions($a, false);
    foreach ((array)$acts as $x) {
        if ($x['kind'] === 'delete') { $acts = null; }
    }
    if ($acts === null) {
        cm_reply($ctx, cm_t('outcome_usage'));
        return;
    }
    $s[$key] = $acts;
    cm_settings_save($ctx['pdo'], $ctx['chat'], $s, (int)$ctx['now']);
    cm_reply($ctx, $acts ? cm_t('outcome_set', ['game' => $game, 'actions' => cm_actions_text($acts)])
        : cm_t('outcome_off', ['game' => $game]), false);
}
```

- [ ] **Step 4: Кубы**

Write `public_html/api/lib/cm/dice.php`:

```php
<?php
// Кубы (этап 3): «кубы @ник» → приглашение (offers.php) → бот бросает 🎲 за
// обоих ответом на приглашение (sendDice), значения берёт из ответа Telegram.
// Больше — победа, поровну — ничья. Итог — в том же сообщении сразу:
// анимация в Telegram доиграет сама. «кубы» без цели — топ. Исход
// проигравшему — dice_outcome («кубы исход мут 10м»), у ничьей исхода нет.

// Бросить кубик ответом на сообщение $replyTo. 0 — бюджет минуты кончился
// или Telegram не бросил.
function cm_dice_throw(array $ctx, int $replyTo): int {
    $chatId = (int)$ctx['chat']['chat_id'];
    if (!cm_budget_take($ctx['pdo'], $chatId, (int)$ctx['now'])) { return 0; }
    $res = ($ctx['send'])('sendDice', ['chat_id' => $chatId, 'emoji' => '🎲',
        'reply_parameters' => ['message_id' => $replyTo, 'allow_sending_without_reply' => true]]);
    $v = (int)($res['result']['dice']['value'] ?? 0);
    return ($v >= 1 && $v <= 6) ? $v : 0;
}

function cm_cmd_dice(array $ctx, array $cmd): void {
    if (!cm_need3($ctx)) { return; }
    $args = $cmd['args'];
    $why = null;
    $t = cm_target($ctx['pdo'], $ctx['msg'], $args, $why);
    if ($t !== null) {
        cm_game_invite($ctx, 'dice', $t, 'dice_offer', 'dice_outcome');
        return;
    }
    if ($why === 'unknown') {
        cm_reply($ctx, cm_t('target_unknown'));
        return;
    }
    cm_game_top($ctx, 'dice', 'dice_top_head', 'dice_top_empty', true);
}

function cm_cmd_dice_outcome(array $ctx, array $cmd): void {
    cm_outcome_cmd($ctx, $cmd, 'dice_outcome');
}

// «Принять»: первым бросает тот, кто позвал.
function cm_offer_yes_dice(array $ctx, array $o): string {
    $va = cm_dice_throw($ctx, $o['msg_id']);
    $vb = $va > 0 ? cm_dice_throw($ctx, $o['msg_id']) : 0;
    if ($vb === 0) { return 'dice_failed'; }
    $pdo = $ctx['pdo'];
    $chatId = $o['chat_id'];
    $a = $o['from_id'];
    $b = $o['to_id'];
    $text = cm_t('dice_result', ['a' => cm_user_name($pdo, $a), 'va' => $va, 'b' => cm_user_name($pdo, $b), 'vb' => $vb]);
    if ($va === $vb) {
        cm_game_score($pdo, $chatId, $a, 'dice', 'draws');
        cm_game_score($pdo, $chatId, $b, 'dice', 'draws');
        cm_edit($ctx, $o['msg_id'], $text . "\n" . cm_t('dice_draw'));
        return '';
    }
    [$win, $lose] = $va > $vb ? [$a, $b] : [$b, $a];
    cm_game_score($pdo, $chatId, $win, 'dice', 'wins');
    cm_game_score($pdo, $chatId, $lose, 'dice', 'losses');
    $out = cm_game_outcome($ctx, 'dice_outcome', $a, $b, $lose, cm_t('reason_dice'));
    cm_edit($ctx, $o['msg_id'], $text . "\n" . cm_t('game_won', ['name' => cm_user_name($pdo, $win)]) . $out);
    return '';
}
```

- [ ] **Step 5: Команды, настройки, кнопка `p`, cron, тексты, подключение**

Edit `public_html/api/lib/cm/db.php`, в `CM_DEFAULTS` — фрагмент

```php
    'raid_greet_at' => 0,
];
```

заменить на

```php
    'raid_greet_at' => 0,
    // этап 3: исход проигравшему в дуэли и кубах — действия в формате
    // триггеров; [] — проигрыш без наказания, исход включает модератор
    'duel_outcome'  => [],
    'dice_outcome'  => [],
];
```

Edit `public_html/api/lib/cm/parse.php` — перед строкой

```php
    'помощь'             => ['help', null, 0, true, false],
```

вставить

```php
    'кубы'               => ['dice', 'игры', 0, false, false],
    'кубы исход'         => ['dice_outcome', 'триггеры', 3, false, false],
```

Edit `public_html/api/lib/cm/parse.php`, в `CM_BARE_ARGS` — фрагмент

```php
    'rp'          => '~^$~',
];
```

заменить на

```php
    'rp'          => '~^$~',
    'dice'        => '~^$~',
];
```

Edit `public_html/api/lib/cm/router.php` — фрагмент

```php
// Кнопки «cm:»: r — репорт, k — массовый кик, o — передача создателя.
```

заменить на

```php
// Кнопки «cm:»: r — репорт, k — массовый кик, o — передача создателя,
// p — приглашение этапа 3 (offers.php).
```

Edit `public_html/api/lib/cm/router.php` — фрагмент

```php
        } elseif ($kind === 'o' && count($p) === 4) {
            $answer = cm_owner_callback($ctx, $q, (int)$p[2], (int)$p[3]);
        }
```

заменить на

```php
        } elseif ($kind === 'o' && count($p) === 4) {
            $answer = cm_owner_callback($ctx, $q, (int)$p[2], (int)$p[3]);
        } elseif ($kind === 'p' && count($p) === 4) {
            $answer = cm_offer_callback($ctx, $q, (int)$p[2], (string)$p[3]);
        }
```

Edit `public_html/api/lib/cm/cron.php` — фрагмент

```php
function cm_cron_tick(PDO $pdo, array $cfg, callable $send, int $now): array {
    return ['timers' => cm_timers_tick($pdo, $cfg, $send, $now), 'deleted' => cm_queue_tick($pdo, $send, $now)];
}
```

заменить на

```php
// games — закрытые приглашения и ходы дуэлей этапа 3 (offers.php).
function cm_cron_tick(PDO $pdo, array $cfg, callable $send, int $now): array {
    return ['timers' => cm_timers_tick($pdo, $cfg, $send, $now), 'deleted' => cm_queue_tick($pdo, $send, $now),
            'games' => cm_games_tick($pdo, $cfg, $send, $now)];
}
```

Edit `public_html/api/lib/cm/texts.php` — после строки

```php
    'rp_done'          => '{emoji} {from} {verb} {to}',
```

добавить

```php
    // приглашения и общее для игр
    'btn_decline'      => 'Отказаться',
    'offer_accepted'   => 'Принято!',
    'offer_declined'   => '{name} отказывается.',
    'offer_expired'    => '⌛ Приглашение истекло.',
    'offer_replaced'   => 'Приглашение отменено: отправлено новое.',
    'game_stake'       => "\nСтавка: проигравшему — {actions}.",
    'game_won'         => '🏆 Победитель — {name}!',
    'game_outcome'     => "\n⚖️ {name}: {done}",
    'game_top_line'    => '{n}. {name} — {score}',
    'outcome_usage'    => 'Так: «дуэли исход мут 10м», «кубы исход ничего». Действия: варн, мут <срок>, кик, бан <срок> — до трёх.',
    'outcome_set'      => 'Проигравший в {game} получит: {actions}.',
    'outcome_off'      => 'Проигрыш в {game} — без наказания.',
    'reason_dice'      => 'проигрыш в кубах',
    // кубы
    'dice_offer'       => '🎲 {to}, {from} предлагает бросить кубы!{stake}',
    'dice_result'      => '🎲 Кубы: {a} — {va}, {b} — {vb}',
    'dice_draw'        => '🤝 Ничья!',
    'dice_failed'      => 'Кубы не бросились: Telegram не ответил. Попробуйте ещё раз.',
    'dice_top_head'    => '🎲 Лучшие в кубах:',
    'dice_top_empty'   => 'В кубы здесь ещё не играли.',
```

Edit `public_html/api/lib/cm/cm.php`: строку `          'fun', 'router', 'cron'] as $cm_part) {` заменить на `          'fun', 'offers', 'dice', 'router', 'cron'] as $cm_part) {`.

- [ ] **Step 6: Тесты проходят, остальное цело**

Run: `/c/xampp/php/php.exe tests/cm_offers_test.php`
Expected: `32 assertions, 0 failures`.

Run: `/c/xampp/php/php.exe tests/cm_dice_test.php`
Expected: `45 assertions, 0 failures`.

Run: `/c/xampp/php/php.exe tests/cm_router_test.php`
Expected: `66 assertions, 0 failures`.

Run:
```bash
for t in tests/cm_*_test.php tests/php74_compat_test.php tests/telegram_test.php; do /c/xampp/php/php.exe -d extension=gd "$t" > /dev/null 2>&1 || echo "FAIL $t"; done; echo done
```
Expected: одна строка `done`.

- [ ] **Step 7: Commit**

```bash
git add public_html/api/lib/cm/offers.php public_html/api/lib/cm/dice.php tests/cm_offers_test.php tests/cm_dice_test.php tests/cm_helpers.php tests/cm_router_test.php public_html/api/lib/cm/db.php public_html/api/lib/cm/parse.php public_html/api/lib/cm/router.php public_html/api/lib/cm/cron.php public_html/api/lib/cm/texts.php public_html/api/lib/cm/cm.php
git commit -m "feat(cm): приглашения с кнопками, кубы и исход проигравшему" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git show --stat HEAD
```
Expected: 12 файлов, без `Bin`.

---

### Task 4: Дуэль

**Files:**
- Create: `public_html/api/lib/cm/duel.php`
- Modify: `public_html/api/lib/cm/offers.php` (`cm_games_tick`), `parse.php`, `router.php` (`cm_on_callback`), `texts.php`, `cm.php`
- Modify: `tests/cm_router_test.php` (кнопка хода через вебхук)
- Test: `tests/cm_duel_test.php`

**Interfaces:**
- Consumes: `cm_rand`, `cm_need3`, `cm_ready3` (Task 1); `cm_offer_send` через `cm_game_invite`, `cm_edit`, `cm_tick_ctx`, `cm_game_score`, `cm_game_top`, `cm_game_outcome`, `cm_outcome_cmd`, `cm_offer_callback` (вызывает `cm_offer_yes_duel`), `cm_press` в тестах (Task 3); этап 1: `cm_need_target`, `cm_user_name`.
- Produces:
  - `CM_DUEL_TURN` (120), `CM_DUEL_AIMS_MAX` (3)
  - `cm_duel_chance(int $aims): int` — 30 + 20 × прицел, не больше 90
  - `cm_duel_get(PDO $pdo, int $id): ?array` — строка `cm_games` + `aims` (`['a' => int, 'b' => int]`)
  - `cm_duel_board(PDO $pdo, array $g, string $last): string`, `cm_duel_buttons(int $id): array`
  - `cm_offer_yes_duel(array $ctx, array $o): string`, `cm_duel_callback(array $ctx, array $q, int $id, string $act): string`
  - `cm_duel_end(array $ctx, array $g, int $winner, string $last): void`, `cm_duel_timeout(array $ctx, array $g): bool`, `cm_duel_tick(PDO $pdo, array $cfg, callable $send, int $now): int` (зовёт `cm_games_tick`)
  - обработчики `cm_cmd_duel`, `cm_cmd_duel_top`, `cm_cmd_duel_outcome`
  - роутер: кнопка `cm:d:<id>:s|a|k` → `cm_duel_callback`
  - Тексты: `duel_offer`, `duel_head`, `duel_aims`, `duel_turn`, `duel_first`, `duel_miss`, `duel_hit`, `duel_aimed`, `duel_knocked`, `duel_timeout`, `duel_btn_shoot`, `duel_btn_aim`, `duel_btn_knock`, `duel_not_turn`, `duel_aim_max`, `duel_ans_hit`, `duel_ans_miss`, `duel_ans_ok`, `duel_top_head`, `duel_top_empty`, `reason_duel`

- [ ] **Step 1: Падающие тесты**

Write `tests/cm_duel_test.php`:

```php
<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';

// Дуэль (этап 3): api/lib/cm/duel.php. Шанс выстрела и жребий — через
// подставную случайность (cm_seq), ход — нажатием кнопки (cm_press).

function dl_people(PDO $pdo): void {
    cm_person($pdo, 7, 'user7', 'U7');
    cm_person($pdo, 20, 'petya', 'Петя');
}

// Вызов от 7 к Пете и «Принять» в момент $now; $first — жребий (0 — первым
// ходит вызвавший). Возвращает id дуэли.
function dl_start(PDO $pdo, array &$log, int $first = 0, int $now = CM_NOW): int {
    cm_do(cm_ctx_for($pdo, $log, 7, '', [], [], $now), 'дуэль @petya');
    $offer = (int)$pdo->query('SELECT MAX(id) FROM cm_offers')->fetchColumn();
    assert_eq(cm_t('offer_accepted'), cm_press($pdo, $log, 20, 'cm:p:' . $offer . ':y', [], $now, cm_seq([$first])));
    return (int)$pdo->query('SELECT MAX(id) FROM cm_games')->fetchColumn();
}

function dl_last_edit(array $log): array {
    $e = cm_calls($log, 'editMessageText');
    return $e[count($e) - 1][1];
}

test('шанс выстрела: 30% + 20% за прицел, потолок 90%', function () {
    assert_eq([30, 50, 70, 90, 90], array_map('cm_duel_chance', [0, 1, 2, 3, 4]));
});

test('дуэль: вызов, жребий, кнопки ходов в том же сообщении', function () {
    $pdo = cm_test_pdo();
    $log = [];
    dl_people($pdo);
    $id = dl_start($pdo, $log);
    assert_eq('⚔️ Петя, U7 вызывает вас на дуэль!', cm_texts($log)[0]);
    $e = dl_last_edit($log);
    assert_eq("⚔️ Дуэль: U7 против Петя\n🎯 Прицелы: U7 — 0, Петя — 0\nЖребий: первым ходит U7.\nХодит: U7. На ход — 2 минуты.", $e['text']);
    assert_eq([[['text' => '🔫 Выстрел', 'callback_data' => 'cm:d:1:s']],
               [['text' => '🎯 Прицелиться', 'callback_data' => 'cm:d:1:a'], ['text' => '💨 Сбить прицел', 'callback_data' => 'cm:d:1:k']]],
        $e['reply_markup']['inline_keyboard']);
    $g = cm_duel_get($pdo, $id);
    assert_eq([7, 20, 7, 'on', cm_offer_get($pdo, 1)['msg_id']], [$g['a_id'], $g['b_id'], $g['turn_id'], $g['status'], $g['msg_id']]);
    assert_eq($g['msg_id'], $e['message_id']);
    assert_eq(1, count(cm_calls($log, 'sendMessage')), 'новых сообщений нет');
    $id2 = dl_start($pdo, $log, 1);
    assert_eq(20, cm_duel_get($pdo, $id2)['turn_id'], 'жребий: первым — Петя');
});

test('ходит только тот, чей ход; чужим — «не для вас»', function () {
    $pdo = cm_test_pdo();
    $log = [];
    dl_people($pdo);
    dl_start($pdo, $log);
    $edits = count(cm_calls($log, 'editMessageText'));
    assert_eq(cm_t('duel_not_turn'), cm_press($pdo, $log, 20, 'cm:d:1:s'));
    assert_eq(cm_t('not_yours'), cm_press($pdo, $log, 30, 'cm:d:1:a'));
    assert_eq('', cm_press($pdo, $log, 7, 'cm:d:1:z'), 'неизвестная буква');
    assert_eq($edits, count(cm_calls($log, 'editMessageText')));
});

test('прицел, сбить прицел; больше трёх прицелов нельзя, ход не переходит', function () {
    $pdo = cm_test_pdo();
    $log = [];
    dl_people($pdo);
    dl_start($pdo, $log);
    assert_eq(cm_t('duel_ans_ok'), cm_press($pdo, $log, 7, 'cm:d:1:a', [], CM_NOW + 5));
    assert_eq("⚔️ Дуэль: U7 против Петя\n🎯 Прицелы: U7 — 1, Петя — 0\n🎯 U7 целится.\nХодит: Петя. На ход — 2 минуты.",
        dl_last_edit($log)['text']);
    cm_press($pdo, $log, 20, 'cm:d:1:k', [], CM_NOW + 10);
    assert_eq("⚔️ Дуэль: U7 против Петя\n🎯 Прицелы: U7 — 0, Петя — 0\n💨 Петя сбивает прицел U7.\nХодит: U7. На ход — 2 минуты.",
        dl_last_edit($log)['text']);
    foreach ([7, 20, 7, 20, 7, 20] as $i => $who) { cm_press($pdo, $log, $who, 'cm:d:1:a', [], CM_NOW + 20 + $i); }
    $g = cm_duel_get($pdo, 1);
    assert_eq([['a' => 3, 'b' => 3], 7, CM_NOW + 25], [$g['aims'], $g['turn_id'], $g['updated_at']]);
    $edits = count(cm_calls($log, 'editMessageText'));
    assert_eq(cm_t('duel_aim_max'), cm_press($pdo, $log, 7, 'cm:d:1:a', [], CM_NOW + 30));
    assert_eq([7, CM_NOW + 25], [cm_duel_get($pdo, 1)['turn_id'], cm_duel_get($pdo, 1)['updated_at']]);
    assert_eq($edits, count(cm_calls($log, 'editMessageText')));
});

test('выстрел: промах обнуляет свои прицелы, попадание — победа и счёт', function () {
    $pdo = cm_test_pdo();
    $log = [];
    dl_people($pdo);
    dl_start($pdo, $log);
    cm_press($pdo, $log, 7, 'cm:d:1:a', [], CM_NOW + 5);
    cm_press($pdo, $log, 20, 'cm:d:1:a', [], CM_NOW + 10);
    assert_eq(cm_t('duel_ans_miss'), cm_press($pdo, $log, 7, 'cm:d:1:s', [], CM_NOW + 15, cm_seq([51])));
    assert_eq("⚔️ Дуэль: U7 против Петя\n🎯 Прицелы: U7 — 0, Петя — 1\n💥 U7 стреляет (50%) — мимо!\nХодит: Петя. На ход — 2 минуты.",
        dl_last_edit($log)['text']);
    assert_eq(cm_t('duel_ans_hit'), cm_press($pdo, $log, 20, 'cm:d:1:s', [], CM_NOW + 20, cm_seq([50])));
    $e = dl_last_edit($log);
    assert_eq("⚔️ Дуэль: U7 против Петя\n💥 Петя стреляет (50%) — попал!\n🏆 Победитель — Петя!", $e['text']);
    assert_eq(false, isset($e['reply_markup']), 'кнопки сняты');
    $g = cm_duel_get($pdo, 1);
    assert_eq(['done', 20], [$g['status'], $g['winner_id']]);
    assert_eq(['wins' => 1, 'losses' => 0, 'draws' => 0], cm_game_stat($pdo, CM_CHAT, 20, 'duel'));
    assert_eq(['wins' => 0, 'losses' => 1, 'draws' => 0], cm_game_stat($pdo, CM_CHAT, 7, 'duel'));
    assert_eq(cm_t('offer_stale'), cm_press($pdo, $log, 7, 'cm:d:1:s', [], CM_NOW + 25));
});

test('просрочка хода: cron отдаёт победу сопернику, нажатие после 2 минут — тоже', function () {
    $pdo = cm_test_pdo();
    $log = [];
    dl_people($pdo);
    dl_start($pdo, $log);
    assert_eq(0, cm_games_tick($pdo, cm_cfg(), cm_rec($log), CM_NOW + 119));
    assert_eq(1, cm_games_tick($pdo, cm_cfg(), cm_rec($log), CM_NOW + 120));
    assert_eq("⚔️ Дуэль: U7 против Петя\n⌛ U7: ход просрочен.\n🏆 Победитель — Петя!", dl_last_edit($log)['text']);
    assert_eq(['done', 20], [cm_duel_get($pdo, 1)['status'], cm_duel_get($pdo, 1)['winner_id']]);
    assert_eq(1, cm_game_stat($pdo, CM_CHAT, 20, 'duel')['wins']);
    assert_eq(0, cm_games_tick($pdo, cm_cfg(), cm_rec($log), CM_NOW + 300), 'второй раз не трогает');
    $id = dl_start($pdo, $log, 0, CM_NOW + 1000);
    cm_press($pdo, $log, 7, 'cm:d:' . $id . ':a', [], CM_NOW + 1010);
    assert_eq(cm_t('offer_stale'), cm_press($pdo, $log, 20, 'cm:d:' . $id . ':s', [], CM_NOW + 1130));
    assert_eq(['done', 7], [cm_duel_get($pdo, $id)['status'], cm_duel_get($pdo, $id)['winner_id']]);
    assert_eq("⚔️ Дуэль: U7 против Петя\n⌛ Петя: ход просрочен.\n🏆 Победитель — U7!", dl_last_edit($log)['text']);
});

test('исход: проигравшему — мут от бота; «ничего» — без него; модератор — игра без ставки', function () {
    $pdo = cm_test_pdo();
    $log = [];
    dl_people($pdo);
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), 'дуэли исход мут 10м');
    assert_eq('Проигравший в дуэли получит: мут 10 мин.', cm_last_text($log));
    $id = dl_start($pdo, $log);
    assert_eq("⚔️ Петя, U7 вызывает вас на дуэль!\nСтавка: проигравшему — мут 10 мин.", cm_texts($log)[1]);
    cm_press($pdo, $log, 7, 'cm:d:' . $id . ':s', [], CM_NOW + 5, cm_seq([1]));
    $r = cm_calls($log, 'restrictChatMember');
    assert_eq([1, 20, CM_NOW + 605], [count($r), $r[0][1]['user_id'], $r[0][1]['until_date']], 'мут 10 минут от выстрела');
    assert_eq("⚔️ Дуэль: U7 против Петя\n💥 U7 стреляет (30%) — попал!\n🏆 Победитель — U7!\n⚖️ Петя: мут 10 мин",
        dl_last_edit($log)['text']);
    assert_eq('проигрыш в дуэли', $pdo->query('SELECT reason FROM cm_punishments')->fetchColumn());
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), 'дуэли исход ничего');
    $id = dl_start($pdo, $log);
    cm_press($pdo, $log, 7, 'cm:d:' . $id . ':s', [], CM_NOW + 5, cm_seq([1]));
    assert_eq(1, count(cm_calls($log, 'restrictChatMember')), 'исход выключен');
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), 'дуэли исход мут 10м');
    cm_lvl($pdo, 20, 2);
    $id = dl_start($pdo, $log);
    assert_eq('⚔️ Петя, U7 вызывает вас на дуэль!', cm_texts($log)[count(cm_texts($log)) - 1], 'без строки ставки');
    cm_press($pdo, $log, 7, 'cm:d:' . $id . ':s', [], CM_NOW + 5, cm_seq([1]));
    assert_eq(1, count(cm_calls($log, 'restrictChatMember')), 'проигравший с рангом — без наказания');
});

test('дуэли — топ побед; без префикса «дуэль» — только с целью; отказы', function () {
    $pdo = cm_test_pdo();
    $log = [];
    dl_people($pdo);
    $ins = $pdo->prepare("INSERT INTO cm_game_stats (chat_id, user_id, kind, wins, losses, draws) VALUES (:c, :u, :k, :w, :l, 0)");
    foreach ([[7, 'duel', 1, 0], [20, 'duel', 5, 2], [21, 'dice', 9, 0]] as $r) {
        $ins->execute([':c' => CM_CHAT, ':u' => $r[0], ':k' => $r[1], ':w' => $r[2], ':l' => $r[3]]);
    }
    cm_do(cm_ctx_for($pdo, $log, 7), 'дуэли');
    assert_eq("⚔️ Лучшие дуэлянты:\n1. Петя — 5 побед / 2 поражения\n2. U7 — 1 победа / 0 поражений", cm_last_text($log));
    cm_do(cm_ctx_for(cm_test_pdo(), $log, 7), 'дуэли');
    assert_eq(cm_t('duel_top_empty'), cm_last_text($log));
    $self = ['reply_to_message' => ['message_id' => 70, 'from' => ['id' => 7, 'first_name' => 'U7']]];
    cm_do(cm_ctx_for($pdo, $log, 7, '', $self), 'дуэль');
    assert_eq(cm_t('target_self'), cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, 7), 'дуэль');
    assert_eq(cm_t('need_target'), cm_last_text($log));
    $run = function (string $text) use ($pdo, &$log): bool {
        return cm_run_command(cm_ctx_for($pdo, $log, 7, $text), cm_parse($text, CM_BOT));
    };
    assert_eq(true, $run('дуэль @petya'));
    assert_eq(true, $run('дуэли'));
    assert_eq(false, $run('дуэль бы'));
    assert_eq(false, $run('дуэли были'));
    $pdo->exec('DROP TABLE cm_offers');
    cm_do(cm_ctx_for($pdo, $log, 7), 'дуэли');
    assert_eq(cm_t('not_ready2'), cm_last_text($log));
    assert_eq(cm_t('offer_stale'), cm_press($pdo, $log, 7, 'cm:d:1:s'));
});

run_tests();
```

Edit `tests/cm_router_test.php` — перед строкой

```php
test('вебхук: правки сообщений и право удалять в группах', function () {
```

вставить

```php
test('кнопка хода дуэли идёт через вебхук', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 20, 'petya', 'Петя');
    rt_run($pdo, cm_gmsg(7, 'дуэль @petya'), $log);
    $chat = ['id' => CM_CHAT, 'type' => 'supergroup', 'title' => 'Тестовый чат'];
    $q = ['id' => 'q1', 'from' => ['id' => 20, 'first_name' => 'Петя'], 'data' => 'cm:p:1:y',
          'message' => ['message_id' => cm_offer_get($pdo, 1)['msg_id'], 'chat' => $chat, 'text' => 'вызов']];
    cm_handle_update($pdo, cm_cfg(), cm_rec($log), ['callback_query' => $q], CM_NOW);
    $q['from'] = ['id' => 30, 'first_name' => 'Чужой'];
    $q['data'] = 'cm:d:1:s';
    cm_handle_update($pdo, cm_cfg(), cm_rec($log), ['callback_query' => $q], CM_NOW);
    $ans = array_map(function ($c) { return $c[1]['text']; }, cm_calls($log, 'answerCallbackQuery'));
    assert_eq([cm_t('offer_accepted'), cm_t('not_yours')], $ans);
    assert_eq('on', cm_duel_get($pdo, 1)['status']);
});

```

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `/c/xampp/php/php.exe tests/cm_duel_test.php`
Expected: FAIL — первая строка `ERROR - шанс выстрела: …: array_map(): Argument #1 ($callback) must be a valid callback, function "cm_duel_chance" not found …`, дальше `не команда: дуэль @petya`.

- [ ] **Step 3: Дуэль**

Write `public_html/api/lib/cm/duel.php`:

```php
<?php
// Дуэль (этап 3): «дуэль @ник» → приглашение (offers.php) → жребий, кто ходит
// первым. Кнопки cm:d:<id>:s|a|k — выстрел, прицел, сбить прицел; жмёт тот,
// чей ход. Выстрел попадает с шансом 30% + 20% за каждый свой прицел (не
// больше трёх, потолок 90%); промах обнуляет свои прицелы. Прицел — +1,
// «сбить прицел» обнуляет прицелы соперника; ход переходит. Нет хода 2
// минуты — проиграл тот, чей был ход (cm_duel_tick из cron или нажатие
// позже срока). Сообщение одно — приглашение: бот правит его после каждого
// хода. Счёт — cm_game_stats, исход проигравшему — duel_outcome.

const CM_DUEL_TURN     = 120;
const CM_DUEL_AIMS_MAX = 3;

function cm_duel_chance(int $aims): int {
    return min(90, 30 + 20 * min(CM_DUEL_AIMS_MAX, max(0, $aims)));
}

// Дуэль по id; aims — прицелы ['a' => …, 'b' => …] из state.
function cm_duel_get(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT * FROM cm_games WHERE id = :id AND kind = 'duel'");
    $st->execute([':id' => $id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) { return null; }
    foreach (['id', 'chat_id', 'a_id', 'b_id', 'turn_id', 'msg_id', 'winner_id', 'updated_at'] as $k) { $r[$k] = (int)$r[$k]; }
    $s = json_decode((string)$r['state'], true);
    $r['aims'] = ['a' => (int)($s['a'] ?? 0), 'b' => (int)($s['b'] ?? 0)];
    $r['status'] = (string)$r['status'];
    return $r;
}

// Текст идущей дуэли: кто против кого, прицелы, последний ход, чей ход.
function cm_duel_board(PDO $pdo, array $g, string $last): string {
    $a = cm_user_name($pdo, $g['a_id']);
    $b = cm_user_name($pdo, $g['b_id']);
    return cm_t('duel_head', ['a' => $a, 'b' => $b]) . "\n"
        . cm_t('duel_aims', ['a' => $a, 'na' => $g['aims']['a'], 'b' => $b, 'nb' => $g['aims']['b']]) . "\n"
        . $last . "\n" . cm_t('duel_turn', ['name' => cm_user_name($pdo, $g['turn_id'])]);
}

function cm_duel_buttons(int $id): array {
    return [[['text' => cm_t('duel_btn_shoot'), 'callback_data' => 'cm:d:' . $id . ':s']],
            [['text' => cm_t('duel_btn_aim'), 'callback_data' => 'cm:d:' . $id . ':a'],
             ['text' => cm_t('duel_btn_knock'), 'callback_data' => 'cm:d:' . $id . ':k']]];
}

// «Принять»: жребий и доска с кнопками вместо текста приглашения.
function cm_offer_yes_duel(array $ctx, array $o): string {
    $pdo = $ctx['pdo'];
    $first = cm_rand($ctx, 0, 1) === 0 ? $o['from_id'] : $o['to_id'];
    $pdo->prepare("INSERT INTO cm_games (chat_id, kind, a_id, b_id, turn_id, state, msg_id, status, winner_id, updated_at)
                   VALUES (:c, 'duel', :a, :b, :t, :s, :m, 'on', 0, :n)")
        ->execute([':c' => $o['chat_id'], ':a' => $o['from_id'], ':b' => $o['to_id'], ':t' => $first,
                   ':s' => json_encode(['a' => 0, 'b' => 0]), ':m' => $o['msg_id'], ':n' => (int)$ctx['now']]);
    $g = cm_duel_get($pdo, (int)$pdo->lastInsertId());
    cm_edit($ctx, $g['msg_id'], cm_duel_board($pdo, $g, cm_t('duel_first', ['name' => cm_user_name($pdo, $first)])),
        cm_duel_buttons($g['id']));
    return '';
}

/**
 * Ход кнопкой; возвращает текст для answerCallbackQuery. Ход записывается
 * условием «ход всё ещё мой»: второе быстрое нажатие его не повторит.
 */
function cm_duel_callback(array $ctx, array $q, int $id, string $act): string {
    if (!in_array($act, ['s', 'a', 'k'], true)) { return ''; }
    $pdo = $ctx['pdo'];
    $g = cm_ready3($pdo) ? cm_duel_get($pdo, $id) : null;
    if ($g === null || $g['chat_id'] !== (int)$ctx['chat']['chat_id'] || $g['status'] !== 'on') { return cm_t('offer_stale'); }
    $me = (int)$ctx['actor'];
    if ($me !== $g['a_id'] && $me !== $g['b_id']) { return cm_t('not_yours'); }
    if ($me !== $g['turn_id']) { return cm_t('duel_not_turn'); }
    $now = (int)$ctx['now'];
    if ($now - $g['updated_at'] >= CM_DUEL_TURN) {
        cm_duel_timeout($ctx, $g);
        return cm_t('offer_stale');
    }
    $mine = $me === $g['a_id'] ? 'a' : 'b';
    $their = $mine === 'a' ? 'b' : 'a';
    $other = $mine === 'a' ? $g['b_id'] : $g['a_id'];
    $name = cm_user_name($pdo, $me);
    $aims = $g['aims'];
    if ($act === 'a' && $aims[$mine] >= CM_DUEL_AIMS_MAX) { return cm_t('duel_aim_max'); }
    if ($act === 's') {
        $chance = cm_duel_chance($aims[$mine]);
        if (cm_rand($ctx, 1, 100) <= $chance) {
            $st = $pdo->prepare("UPDATE cm_games SET status = 'done', winner_id = :w, updated_at = :n
                WHERE id = :id AND status = 'on' AND turn_id = :me");
            $st->execute([':w' => $me, ':n' => $now, ':id' => $id, ':me' => $me]);
            if ($st->rowCount() === 0) { return cm_t('offer_stale'); }
            cm_duel_end($ctx, $g, $me, cm_t('duel_hit', ['name' => $name, 'chance' => $chance]));
            return cm_t('duel_ans_hit');
        }
        $aims[$mine] = 0;
        $last = cm_t('duel_miss', ['name' => $name, 'chance' => $chance]);
    } elseif ($act === 'a') {
        $aims[$mine]++;
        $last = cm_t('duel_aimed', ['name' => $name]);
    } else {
        $aims[$their] = 0;
        $last = cm_t('duel_knocked', ['name' => $name, 'other' => cm_user_name($pdo, $other)]);
    }
    $st = $pdo->prepare("UPDATE cm_games SET state = :s, turn_id = :t, updated_at = :n WHERE id = :id AND status = 'on' AND turn_id = :me");
    $st->execute([':s' => json_encode($aims), ':t' => $other, ':n' => $now, ':id' => $id, ':me' => $me]);
    if ($st->rowCount() === 0) { return cm_t('offer_stale'); }
    $g['aims'] = $aims;
    $g['turn_id'] = $other;
    cm_edit($ctx, $g['msg_id'], cm_duel_board($pdo, $g, $last), cm_duel_buttons($id));
    return cm_t($act === 's' ? 'duel_ans_miss' : 'duel_ans_ok');
}

// Конец дуэли (строка уже помечена done): счёт, исход, итог без кнопок.
function cm_duel_end(array $ctx, array $g, int $winner, string $last): void {
    $pdo = $ctx['pdo'];
    $loser = $winner === $g['a_id'] ? $g['b_id'] : $g['a_id'];
    cm_game_score($pdo, $g['chat_id'], $winner, 'duel', 'wins');
    cm_game_score($pdo, $g['chat_id'], $loser, 'duel', 'losses');
    $out = cm_game_outcome($ctx, 'duel_outcome', $g['a_id'], $g['b_id'], $loser, cm_t('reason_duel'));
    cm_edit($ctx, $g['msg_id'], cm_t('duel_head', ['a' => cm_user_name($pdo, $g['a_id']), 'b' => cm_user_name($pdo, $g['b_id'])])
        . "\n" . $last . "\n" . cm_t('game_won', ['name' => cm_user_name($pdo, $winner)]) . $out);
}

// Ход просрочен: проиграл тот, чей был ход. false — дуэль уже закончилась
// или ход сделан, пока мы смотрели.
function cm_duel_timeout(array $ctx, array $g): bool {
    $winner = $g['turn_id'] === $g['a_id'] ? $g['b_id'] : $g['a_id'];
    $st = $ctx['pdo']->prepare("UPDATE cm_games SET status = 'done', winner_id = :w
        WHERE id = :id AND status = 'on' AND turn_id = :t AND updated_at = :u");
    $st->execute([':w' => $winner, ':id' => $g['id'], ':t' => $g['turn_id'], ':u' => $g['updated_at']]);
    if ($st->rowCount() === 0) { return false; }
    cm_duel_end($ctx, $g, $winner, cm_t('duel_timeout', ['name' => cm_user_name($ctx['pdo'], $g['turn_id'])]));
    return true;
}

// Из cm_games_tick: дуэли, где ход просрочен. Бота в чате нет — дуэль
// закрывается без счёта.
function cm_duel_tick(PDO $pdo, array $cfg, callable $send, int $now): int {
    $st = $pdo->prepare("SELECT id FROM cm_games WHERE kind = 'duel' AND status = 'on' AND updated_at <= :t ORDER BY id LIMIT 50");
    $st->execute([':t' => $now - CM_DUEL_TURN]);
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $g = cm_duel_get($pdo, (int)$id);
        if ($g === null) { continue; }
        $ctx = cm_tick_ctx($pdo, $cfg, $send, $g['chat_id'], $now);
        if ($ctx === null) {
            $pdo->prepare("UPDATE cm_games SET status = 'done' WHERE id = :id")->execute([':id' => $g['id']]);
            continue;
        }
        if (cm_duel_timeout($ctx, $g)) { $n++; }
    }
    return $n;
}

// --------------------------------------------------------------------------
//  Команды
// --------------------------------------------------------------------------

function cm_cmd_duel(array $ctx, array $cmd): void {
    if (!cm_need3($ctx)) { return; }
    $args = $cmd['args'];
    $t = cm_need_target($ctx, $args);
    if ($t !== null) { cm_game_invite($ctx, 'duel', $t, 'duel_offer', 'duel_outcome'); }
}

function cm_cmd_duel_top(array $ctx, array $cmd): void {
    if (!cm_need3($ctx)) { return; }
    cm_game_top($ctx, 'duel', 'duel_top_head', 'duel_top_empty', false);
}

function cm_cmd_duel_outcome(array $ctx, array $cmd): void {
    cm_outcome_cmd($ctx, $cmd, 'duel_outcome');
}
```

- [ ] **Step 4: Cron, команды, кнопка `d`, тексты, подключение**

Edit `public_html/api/lib/cm/offers.php`, в `cm_games_tick()` — фрагмент

```php
        $n++;
    }
    return $n;
}
```

заменить на

```php
        $n++;
    }
    return $n + cm_duel_tick($pdo, $cfg, $send, $now);
}
```

Edit `public_html/api/lib/cm/parse.php` — перед строкой

```php
    'помощь'             => ['help', null, 0, true, false],
```

вставить

```php
    'дуэль'              => ['duel', 'игры', 0, false, true],
    'дуэли'              => ['duel_top', 'игры', 0, true, false],
    'дуэли исход'        => ['duel_outcome', 'триггеры', 3, false, false],
```

Edit `public_html/api/lib/cm/parse.php`, в `CM_BARE_ARGS` — фрагмент

```php
    'dice'        => '~^$~',
];
```

заменить на

```php
    'dice'        => '~^$~',
    'duel'        => '~^$~',
];
```

Edit `public_html/api/lib/cm/router.php` — фрагмент

```php
// p — приглашение этапа 3 (offers.php).
```

заменить на

```php
// p — приглашение, d — ход в дуэли (этап 3: offers.php, duel.php).
```

Edit `public_html/api/lib/cm/router.php` — фрагмент

```php
        } elseif ($kind === 'p' && count($p) === 4) {
            $answer = cm_offer_callback($ctx, $q, (int)$p[2], (string)$p[3]);
        }
```

заменить на

```php
        } elseif ($kind === 'p' && count($p) === 4) {
            $answer = cm_offer_callback($ctx, $q, (int)$p[2], (string)$p[3]);
        } elseif ($kind === 'd' && count($p) === 4) {
            $answer = cm_duel_callback($ctx, $q, (int)$p[2], (string)$p[3]);
        }
```

Edit `public_html/api/lib/cm/texts.php` — после строки

```php
    'dice_top_empty'   => 'В кубы здесь ещё не играли.',
```

добавить

```php
    // дуэль
    'duel_offer'       => '⚔️ {to}, {from} вызывает вас на дуэль!{stake}',
    'duel_head'        => '⚔️ Дуэль: {a} против {b}',
    'duel_aims'        => '🎯 Прицелы: {a} — {na}, {b} — {nb}',
    'duel_turn'        => 'Ходит: {name}. На ход — 2 минуты.',
    'duel_first'       => 'Жребий: первым ходит {name}.',
    'duel_miss'        => '💥 {name} стреляет ({chance}%) — мимо!',
    'duel_hit'         => '💥 {name} стреляет ({chance}%) — попал!',
    'duel_aimed'       => '🎯 {name} целится.',
    'duel_knocked'     => '💨 {name} сбивает прицел {other}.',
    'duel_timeout'     => '⌛ {name}: ход просрочен.',
    'duel_btn_shoot'   => '🔫 Выстрел',
    'duel_btn_aim'     => '🎯 Прицелиться',
    'duel_btn_knock'   => '💨 Сбить прицел',
    'duel_not_turn'    => 'Сейчас не ваш ход.',
    'duel_aim_max'     => 'Больше трёх прицелов нельзя: стреляйте или сбейте чужой.',
    'duel_ans_hit'     => 'Попал!',
    'duel_ans_miss'    => 'Мимо!',
    'duel_ans_ok'      => 'Ход сделан.',
    'duel_top_head'    => '⚔️ Лучшие дуэлянты:',
    'duel_top_empty'   => 'Дуэлей здесь ещё не было.',
    'reason_duel'      => 'проигрыш в дуэли',
```

Edit `public_html/api/lib/cm/cm.php`: строку `          'fun', 'offers', 'dice', 'router', 'cron'] as $cm_part) {` заменить на `          'fun', 'offers', 'dice', 'duel', 'router', 'cron'] as $cm_part) {`.

- [ ] **Step 5: Тесты проходят, остальное цело**

Run: `/c/xampp/php/php.exe tests/cm_duel_test.php`
Expected: `65 assertions, 0 failures`.

Run: `/c/xampp/php/php.exe tests/cm_router_test.php`
Expected: `68 assertions, 0 failures`.

Run:
```bash
for t in tests/cm_*_test.php tests/php74_compat_test.php tests/telegram_test.php; do /c/xampp/php/php.exe -d extension=gd "$t" > /dev/null 2>&1 || echo "FAIL $t"; done; echo done
```
Expected: одна строка `done`.

- [ ] **Step 6: Commit**

```bash
git add public_html/api/lib/cm/duel.php tests/cm_duel_test.php tests/cm_router_test.php public_html/api/lib/cm/offers.php public_html/api/lib/cm/parse.php public_html/api/lib/cm/router.php public_html/api/lib/cm/texts.php public_html/api/lib/cm/cm.php
git commit -m "feat(cm): дуэль с ходами кнопками и просрочкой хода" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git show --stat HEAD
```
Expected: 8 файлов, без `Bin`.

---

### Task 5: Пары и браки

**Files:**
- Create: `public_html/api/lib/cm/love.php`
- Modify: `public_html/api/lib/cm/parse.php`, `ranks.php`, `texts.php`, `cm.php`
- Test: `tests/cm_love_test.php`

**Interfaces:**
- Consumes: `cm_need3`, `cm_plural` (Task 1); `cm_botlike` (Task 2; опыт пары копит `cm_couple_xp` оттуда же); `cm_offer_send`, `cm_edit`, `cm_offer_callback` (вызывает `cm_offer_yes_couple|marry|remarry`), `cm_press` в тестах (Task 3); этап 2: `cm_target_or_self(array $ctx, string &$args): ?array`; этап 1: `cm_target`, `cm_need_target`, `cm_user_name`, `cm_display_name`.
- Produces:
  - `CM_COUPLE_XP_LEVEL` (50), `CM_REMARRY_SECS` (259200 — 3 дня), `CM_LOVE_TOP` (10)
  - `cm_couple_of(PDO $pdo, int $chatId, int $userId): ?array`, `cm_marriage_of(…): ?array` — действующая пара / брак (`id, chat_id, a_id, b_id, since, …`, всё `int`); `cm_love_of(PDO $pdo, string $table, int $chatId, int $userId): ?array`
  - `cm_partner(array $row, int $userId): int`, `cm_couple_level(int $xp): int` (1 + xp / 50), `cm_days_text(int $secs): string` («12 дней»)
  - `cm_actor_name(array $ctx): string` — имя автора сообщения контекста (берёт Task 6)
  - `cm_other_err(array $ctx, array $t): string`, `cm_marriage_say(array $ctx, array $m): void`, `cm_marriage_recent(PDO $pdo, int $chatId, int $userId, int $now): ?array`
  - `cm_offer_yes_couple`, `cm_offer_yes_marry`, `cm_offer_yes_remarry`
  - обработчики `cm_cmd_couple_ask`, `cm_cmd_couple_end`, `cm_cmd_couple_show`, `cm_cmd_couple_top`, `cm_cmd_marry`, `cm_cmd_divorce`, `cm_cmd_remarry`, `cm_cmd_marry_top`
  - раздел `отношения`
  - Тексты: `couple_offer`, `couple_done`, `couple_have_me`, `couple_have_them`, `couple_busy`, `couple_none_me`, `couple_none`, `couple_end`, `couple_show`, `couples_head`, `couples_line`, `couples_empty`, `marry_offer`, `marry_done`, `marry_have_me`, `marry_busy`, `marry_none_me`, `marry_show`, `divorce_done`, `remarry_offer`, `remarry_none`, `remarry_ex_busy`, `remarry_done`, `marriages_head`, `marriages_line`, `marriages_empty`

- [ ] **Step 1: Падающий тест**

Write `tests/cm_love_test.php`:

```php
<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';

// Пары и браки (этап 3): api/lib/cm/love.php.

const LV_DAY = 86400;

function lv_people(PDO $pdo): void {
    cm_person($pdo, 7, 'user7', 'U7');
    cm_person($pdo, 20, 'petya', 'Петя');
    cm_person($pdo, 21, 'kolya', 'Коля');
    cm_person($pdo, 22, 'olya', 'Оля');
    cm_person($pdo, 30, 'user30', 'U30');
}

// «Принять» последнее приглашение от имени $who.
function lv_accept(PDO $pdo, array &$log, int $who, int $now = CM_NOW): string {
    $id = (int)$pdo->query('SELECT MAX(id) FROM cm_offers')->fetchColumn();
    return cm_press($pdo, $log, $who, 'cm:p:' . $id . ':y', [], $now);
}

function lv_last_edit(array $log): string {
    $e = cm_calls($log, 'editMessageText');
    return (string)$e[count($e) - 1][1]['text'];
}

function lv_row(PDO $pdo, string $table, int $a, int $b, int $since, int $xp = 0, int $ended = 0): void {
    $xpCols = $table === 'cm_couples' ? ', xp, xp_day, xp_today' : '';
    $xpVals = $table === 'cm_couples' ? ', ' . $xp . ', 0, 0' : '';
    $pdo->exec("INSERT INTO $table (chat_id, a_id, b_id, since$xpCols, ended_at) VALUES (" . CM_CHAT . ", $a, $b, $since$xpVals, $ended)");
}

test('встречаться → «Принять» → пара; «пара» своя и чужая', function () {
    $pdo = cm_test_pdo();
    $log = [];
    lv_people($pdo);
    cm_do(cm_ctx_for($pdo, $log, 7), 'встречаться @petya');
    assert_eq('💞 Петя, U7 предлагает встречаться. Согласны?', cm_last_text($log));
    $o = cm_offer_get($pdo, 1);
    assert_eq(['couple', CM_NOW + 600], [$o['kind'], $o['expires_at']]);
    assert_eq(cm_t('offer_accepted'), lv_accept($pdo, $log, 20));
    assert_eq('💞 U7 и Петя теперь пара!', lv_last_edit($log));
    $c = cm_couple_of($pdo, CM_CHAT, 20);
    assert_eq([7, 20, CM_NOW, 0], [$c['a_id'], $c['b_id'], $c['since'], $c['xp']]);
    $pdo->exec('UPDATE cm_couples SET xp = 117');
    $later = CM_NOW + 12 * LV_DAY + 100;
    cm_do(cm_ctx_for($pdo, $log, 7, '', [], [], $later), 'пара');
    assert_eq('💞 U7 и Петя вместе 12 дней, уровень 3 (опыт 117)', cm_last_text($log));
    $reply = ['reply_to_message' => ['message_id' => 70, 'from' => ['id' => 20, 'first_name' => 'Петя']]];
    cm_do(cm_ctx_for($pdo, $log, 21, '', $reply, [], $later), 'пара');
    assert_eq('💞 U7 и Петя вместе 12 дней, уровень 3 (опыт 117)', cm_last_text($log), 'чужая — ответом');
    cm_do(cm_ctx_for($pdo, $log, 21), 'пара');
    assert_eq('U21 ни с кем не встречается.', cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, 21), '!пара @olya');
    assert_eq('Оля ни с кем не встречается.', cm_last_text($log));
    assert_eq([1, 2, 5], [cm_couple_level(0), cm_couple_level(50), cm_couple_level(249)]);
});

test('одна пара на человека: отказ до приглашения и при «Принять»; себя и ботов нельзя', function () {
    $pdo = cm_test_pdo();
    $log = [];
    lv_people($pdo);
    lv_row($pdo, 'cm_couples', 7, 20, CM_NOW);
    cm_do(cm_ctx_for($pdo, $log, 7), 'встречаться @kolya');
    assert_eq('У вас уже есть пара: Петя.', cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, 21), 'встречаться @petya');
    assert_eq('У Петя уже есть пара.', cm_last_text($log));
    assert_eq(0, (int)$pdo->query('SELECT COUNT(*) FROM cm_offers')->fetchColumn());
    cm_do(cm_ctx_for($pdo, $log, 21), 'встречаться @olya');
    cm_do(cm_ctx_for($pdo, $log, 30), 'встречаться @olya');
    assert_eq(cm_t('offer_accepted'), cm_press($pdo, $log, 22, 'cm:p:2:y'));
    assert_eq(cm_t('couple_busy'), cm_press($pdo, $log, 22, 'cm:p:1:y'), 'Оля уже в паре с U30');
    assert_eq([cm_t('couple_busy'), 'gone'], [lv_last_edit($log), cm_offer_get($pdo, 1)['status']]);
    assert_eq(2, (int)$pdo->query('SELECT COUNT(*) FROM cm_couples')->fetchColumn());
    $self = ['reply_to_message' => ['message_id' => 70, 'from' => ['id' => 21, 'first_name' => 'Коля']]];
    cm_do(cm_ctx_for($pdo, $log, 21, '', $self), 'встречаться');
    assert_eq(cm_t('target_self'), cm_last_text($log));
    $bot = ['reply_to_message' => ['message_id' => 71, 'from' => ['id' => 555, 'is_bot' => true, 'first_name' => 'Бот']]];
    cm_do(cm_ctx_for($pdo, $log, 21, '', $bot), 'встречаться');
    assert_eq(cm_t('target_bot'), cm_last_text($log));
});

test('расстаться: пара закрыта, можно новую', function () {
    $pdo = cm_test_pdo();
    $log = [];
    lv_people($pdo);
    lv_row($pdo, 'cm_couples', 7, 20, CM_NOW - LV_DAY);
    cm_do(cm_ctx_for($pdo, $log, 20), 'расстаться');
    assert_eq('💔 U7 и Петя расстались.', cm_last_text($log));
    assert_eq(0, (int)$pdo->query('SELECT COUNT(*) FROM cm_queue')->fetchColumn(), 'не удаляется');
    assert_eq(CM_NOW, (int)$pdo->query('SELECT ended_at FROM cm_couples')->fetchColumn());
    cm_do(cm_ctx_for($pdo, $log, 20), 'расстаться');
    assert_eq(cm_t('couple_none_me'), cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, 7), 'встречаться @kolya');
    assert_eq('💞 Коля, U7 предлагает встречаться. Согласны?', cm_last_text($log));
});

test('пары — топ по уровню, расставшихся нет', function () {
    $pdo = cm_test_pdo();
    $log = [];
    lv_people($pdo);
    lv_row($pdo, 'cm_couples', 21, 22, CM_NOW, 30);
    lv_row($pdo, 'cm_couples', 7, 20, CM_NOW, 120);
    lv_row($pdo, 'cm_couples', 30, 31, CM_NOW, 900, CM_NOW);
    cm_do(cm_ctx_for($pdo, $log, 7), 'пары');
    assert_eq("💞 Пары чата:\n1. U7 и Петя — уровень 3\n2. Коля и Оля — уровень 1", cm_last_text($log));
    cm_do(cm_ctx_for(cm_test_pdo(), $log, 7), 'пары');
    assert_eq(cm_t('couples_empty'), cm_last_text($log));
});

test('брак: предложение, «брак» свой и чужой, развод', function () {
    $pdo = cm_test_pdo();
    $log = [];
    lv_people($pdo);
    cm_do(cm_ctx_for($pdo, $log, 7), 'брак @petya');
    assert_eq('💍 Петя, U7 делает вам предложение. Согласны?', cm_last_text($log));
    assert_eq(['marry', CM_NOW + 600], [cm_offer_get($pdo, 1)['kind'], cm_offer_get($pdo, 1)['expires_at']]);
    lv_accept($pdo, $log, 20);
    assert_eq('💍 U7 и Петя теперь в браке!', lv_last_edit($log));
    $later = CM_NOW + 125 * LV_DAY;
    cm_do(cm_ctx_for($pdo, $log, 7, '', [], [], $later), 'брак');
    assert_eq('💍 U7 и Петя в браке 125 дней', cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, 21, '', [], [], $later), 'брак @petya');
    assert_eq('💍 U7 и Петя в браке 125 дней', cm_last_text($log), 'цель в браке — показать, а не звать');
    assert_eq(1, (int)$pdo->query('SELECT COUNT(*) FROM cm_offers')->fetchColumn());
    cm_do(cm_ctx_for($pdo, $log, 21), 'брак');
    assert_eq(cm_t('marry_none_me'), cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, 7), 'брак @kolya');
    assert_eq('Вы уже в браке с Петя.', cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, 20, '', [], [], $later), 'развод');
    assert_eq('💔 U7 и Петя развелись. Передумали — «вернуть брак» в течение 3 дней.', cm_last_text($log));
    $m = $pdo->query('SELECT ended_at, ended_by FROM cm_marriages')->fetch(PDO::FETCH_NUM);
    assert_eq([$later, 20], [(int)$m[0], (int)$m[1]]);
    cm_do(cm_ctx_for($pdo, $log, 20), 'развод');
    assert_eq(cm_t('marry_none_me'), cm_last_text($log));
});

test('брак: занятые — отказ при «Принять»; пара и брак друг от друга не зависят', function () {
    $pdo = cm_test_pdo();
    $log = [];
    lv_people($pdo);
    cm_do(cm_ctx_for($pdo, $log, 21), 'брак @olya');
    cm_do(cm_ctx_for($pdo, $log, 30), 'брак @olya');
    lv_accept($pdo, $log, 22);
    assert_eq(cm_t('marry_busy'), cm_press($pdo, $log, 22, 'cm:p:1:y'));
    lv_row($pdo, 'cm_couples', 7, 20, CM_NOW);
    cm_do(cm_ctx_for($pdo, $log, 7), 'брак @kolya');
    lv_accept($pdo, $log, 21);
    assert_eq('💍 U7 и Коля теперь в браке!', lv_last_edit($log), 'пара с Петей браку не мешает');
    cm_do(cm_ctx_for($pdo, $log, 7), 'расстаться');
    assert_eq(21, cm_partner(cm_marriage_of($pdo, CM_CHAT, 7), 7), 'расставание не трогает брак');
});

test('вернуть брак: на 3-й день можно, на 4-й — нет; тот же брак с прежней датой', function () {
    $pdo = cm_test_pdo();
    $log = [];
    lv_people($pdo);
    lv_row($pdo, 'cm_marriages', 7, 20, CM_NOW - 100 * LV_DAY);
    cm_do(cm_ctx_for($pdo, $log, 7), 'развод');
    $day3 = CM_NOW + 2 * LV_DAY + 3600;
    cm_do(cm_ctx_for($pdo, $log, 20, '', [], [], $day3), 'вернуть брак');
    assert_eq('💍 U7, U20 предлагает вернуть брак. Согласны?', cm_last_text($log), 'от кого — имя из сообщения');
    $o = cm_offer_get($pdo, 1);
    assert_eq(['remarry', 7, 1], [$o['kind'], $o['to_id'], $o['ref_id']]);
    lv_accept($pdo, $log, 7, $day3);
    assert_eq('💍 U7 и Петя снова в браке!', lv_last_edit($log));
    assert_eq(1, (int)$pdo->query('SELECT COUNT(*) FROM cm_marriages')->fetchColumn());
    cm_do(cm_ctx_for($pdo, $log, 7, '', [], [], $day3), 'брак');
    assert_eq('💍 U7 и Петя в браке 102 дня', cm_last_text($log), 'дата — прежняя');
    $t2 = CM_NOW + 10 * LV_DAY;
    cm_do(cm_ctx_for($pdo, $log, 7, '', [], [], $t2), 'развод');
    cm_do(cm_ctx_for($pdo, $log, 7, '', [], [], $t2 + 3 * LV_DAY + 3600), 'вернуть брак');
    assert_eq(cm_t('remarry_none'), cm_last_text($log), '4-й день — поздно');
    cm_do(cm_ctx_for($pdo, $log, 7, '', [], [], $t2 + 3 * LV_DAY - 120), 'вернуть брак');
    assert_eq(cm_t('remarry_none'), lv_accept($pdo, $log, 20, $t2 + 3 * LV_DAY + 60), '«Принять» тоже проверяет 3 дня');
    assert_eq(['gone', cm_t('remarry_none')], [cm_offer_get($pdo, 2)['status'], lv_last_edit($log)]);
    cm_do(cm_ctx_for($pdo, $log, 21), 'вернуть брак');
    assert_eq(cm_t('remarry_none'), cm_last_text($log), 'не разводился — нечего вернуть');
});

test('вернуть брак: бывший уже в другом браке, сам в браке', function () {
    $pdo = cm_test_pdo();
    $log = [];
    lv_people($pdo);
    lv_row($pdo, 'cm_marriages', 7, 20, CM_NOW - 10 * LV_DAY, 0, CM_NOW - 3600);
    lv_row($pdo, 'cm_marriages', 20, 22, CM_NOW - 60);
    cm_do(cm_ctx_for($pdo, $log, 7), 'вернуть брак');
    assert_eq('Петя уже в другом браке.', cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, 20), 'вернуть брак');
    assert_eq('Вы уже в браке с Оля.', cm_last_text($log));
});

test('браки — топ по длительности, распавшихся нет', function () {
    $pdo = cm_test_pdo();
    $log = [];
    lv_people($pdo);
    lv_row($pdo, 'cm_marriages', 21, 22, CM_NOW - 10 * LV_DAY);
    lv_row($pdo, 'cm_marriages', 7, 20, CM_NOW - 300 * LV_DAY);
    lv_row($pdo, 'cm_marriages', 30, 31, CM_NOW - 999 * LV_DAY, 0, CM_NOW);
    cm_do(cm_ctx_for($pdo, $log, 7), 'браки');
    assert_eq("💍 Самые долгие браки:\n1. U7 и Петя — 300 дней\n2. Коля и Оля — 10 дней", cm_last_text($log));
    cm_do(cm_ctx_for(cm_test_pdo(), $log, 7), 'браки');
    assert_eq(cm_t('marriages_empty'), cm_last_text($log));
});

test('без префикса — только ровно команда; дк отношения 6; без таблиц — «ещё не настроено»', function () {
    $pdo = cm_test_pdo();
    $log = [];
    lv_people($pdo);
    $run = function (string $text) use ($pdo, &$log): bool {
        return cm_run_command(cm_ctx_for($pdo, $log, 7, $text), cm_parse($text, CM_BOT));
    };
    foreach (['встречаться @petya', 'пара', 'пары', 'брак @kolya', 'брак', 'браки', 'расстаться', 'развод', 'вернуть брак'] as $t) {
        assert_eq(true, $run($t), $t);
    }
    foreach (['встречаться бы', 'пара минут', 'брак это серьёзно', 'развод мостов', 'браки бывают разные'] as $t) {
        assert_eq(false, $run($t), $t);
    }
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), 'дк отношения 6');
    assert_eq(true, $run('!пара'));
    assert_eq(cm_t('cmd_off'), cm_last_text($log));
    $pdo = cm_test_pdo();
    $pdo->exec('DROP TABLE cm_offers');
    cm_do(cm_ctx_for($pdo, $log, 7), 'пара');
    assert_eq(cm_t('not_ready2'), cm_last_text($log));
});

run_tests();
```

- [ ] **Step 2: Убедиться, что тест падает**

Run: `/c/xampp/php/php.exe tests/cm_love_test.php`
Expected: FAIL — `ERROR - встречаться → …: не команда: встречаться @petya`.

- [ ] **Step 3: Пары и браки**

Write `public_html/api/lib/cm/love.php`:

```php
<?php
// Пары и браки (этап 3). У человека в чате — не больше одной пары и одного
// брака; пара и брак друг от друга не зависят: можно и то и другое, с одним
// человеком или с разными. Пара копит опыт от РП (cm_couple_xp, fun.php),
// уровень = 1 + опыт / 50. Развод можно отменить за 3 дня: «вернуть брак»
// продолжает тот же брак с прежней датой. Уход из чата пару и брак не рвёт.

const CM_COUPLE_XP_LEVEL = 50;
const CM_REMARRY_SECS    = 259200;
const CM_LOVE_TOP        = 10;

// Действующая пара или брак человека в чате (строка таблицы, всё — int).
function cm_love_of(PDO $pdo, string $table, int $chatId, int $userId): ?array {
    $st = $pdo->prepare("SELECT * FROM $table WHERE chat_id = :c AND ended_at = 0 AND (a_id = :u OR b_id = :u2)
        ORDER BY id DESC LIMIT 1");
    $st->execute([':c' => $chatId, ':u' => $userId, ':u2' => $userId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? array_map('intval', $r) : null;
}

function cm_couple_of(PDO $pdo, int $chatId, int $userId): ?array {
    return cm_love_of($pdo, 'cm_couples', $chatId, $userId);
}

function cm_marriage_of(PDO $pdo, int $chatId, int $userId): ?array {
    return cm_love_of($pdo, 'cm_marriages', $chatId, $userId);
}

function cm_partner(array $row, int $userId): int {
    return $row['a_id'] === $userId ? $row['b_id'] : $row['a_id'];
}

function cm_couple_level(int $xp): int {
    return 1 + intdiv(max(0, $xp), CM_COUPLE_XP_LEVEL);
}

// Целых суток в $secs: «12 дней».
function cm_days_text(int $secs): string {
    $d = intdiv(max(0, $secs), 86400);
    return $d . ' ' . cm_plural($d, ['день', 'дня', 'дней']);
}

// Имя автора сообщения контекста — как в Telegram сейчас.
function cm_actor_name(array $ctx): string {
    return cm_display_name(is_array($ctx['msg']['from'] ?? null) ? $ctx['msg']['from'] : []);
}

// Себя и ботов нельзя. '' — можно, иначе ключ текста.
function cm_other_err(array $ctx, array $t): string {
    if (cm_botlike($t)) { return 'target_bot'; }
    if ((int)$t['id'] === (int)$ctx['actor']) { return 'target_self'; }
    return '';
}

// --------------------------------------------------------------------------
//  Пары
// --------------------------------------------------------------------------

function cm_cmd_couple_ask(array $ctx, array $cmd): void {
    if (!cm_need3($ctx)) { return; }
    $args = $cmd['args'];
    $t = cm_need_target($ctx, $args);
    if ($t === null) { return; }
    $err = cm_other_err($ctx, $t);
    if ($err !== '') {
        cm_reply($ctx, cm_t($err));
        return;
    }
    $pdo = $ctx['pdo'];
    $chatId = (int)$ctx['chat']['chat_id'];
    $me = (int)$ctx['actor'];
    $mine = cm_couple_of($pdo, $chatId, $me);
    if ($mine !== null) {
        cm_reply($ctx, cm_t('couple_have_me', ['name' => cm_user_name($pdo, cm_partner($mine, $me))]));
        return;
    }
    if (cm_couple_of($pdo, $chatId, (int)$t['id']) !== null) {
        cm_reply($ctx, cm_t('couple_have_them', ['name' => $t['name']]));
        return;
    }
    cm_offer_send($ctx, 'couple', (int)$t['id'], 0, cm_t('couple_offer', ['to' => $t['name'], 'from' => cm_actor_name($ctx)]));
}

function cm_offer_yes_couple(array $ctx, array $o): string {
    $pdo = $ctx['pdo'];
    if (cm_couple_of($pdo, $o['chat_id'], $o['from_id']) !== null || cm_couple_of($pdo, $o['chat_id'], $o['to_id']) !== null) {
        return 'couple_busy';
    }
    $pdo->prepare('INSERT INTO cm_couples (chat_id, a_id, b_id, since, xp, xp_day, xp_today, ended_at)
                   VALUES (:c, :a, :b, :n, 0, 0, 0, 0)')
        ->execute([':c' => $o['chat_id'], ':a' => $o['from_id'], ':b' => $o['to_id'], ':n' => (int)$ctx['now']]);
    cm_edit($ctx, $o['msg_id'], cm_t('couple_done', ['a' => cm_user_name($pdo, $o['from_id']), 'b' => cm_user_name($pdo, $o['to_id'])]));
    return '';
}

function cm_cmd_couple_end(array $ctx, array $cmd): void {
    if (!cm_need3($ctx)) { return; }
    $pdo = $ctx['pdo'];
    $c = cm_couple_of($pdo, (int)$ctx['chat']['chat_id'], (int)$ctx['actor']);
    if ($c === null) {
        cm_reply($ctx, cm_t('couple_none_me'));
        return;
    }
    $pdo->prepare('UPDATE cm_couples SET ended_at = :n WHERE id = :id AND ended_at = 0')
        ->execute([':n' => (int)$ctx['now'], ':id' => $c['id']]);
    cm_reply($ctx, cm_t('couple_end', ['a' => cm_user_name($pdo, $c['a_id']), 'b' => cm_user_name($pdo, $c['b_id'])]), false);
}

// «пара» — своя; ответом или с @ником — чужая.
function cm_cmd_couple_show(array $ctx, array $cmd): void {
    if (!cm_need3($ctx)) { return; }
    $args = $cmd['args'];
    $t = cm_target_or_self($ctx, $args);
    if ($t === null) { return; }
    $pdo = $ctx['pdo'];
    $c = cm_couple_of($pdo, (int)$ctx['chat']['chat_id'], (int)$t['id']);
    if ($c === null) {
        cm_reply($ctx, cm_t('couple_none', ['name' => $t['name']]));
        return;
    }
    cm_reply($ctx, cm_t('couple_show', ['a' => cm_user_name($pdo, $c['a_id']), 'b' => cm_user_name($pdo, $c['b_id']),
        'days' => cm_days_text((int)$ctx['now'] - $c['since']), 'lvl' => cm_couple_level($c['xp']), 'xp' => $c['xp']]));
}

function cm_cmd_couple_top(array $ctx, array $cmd): void {
    if (!cm_need3($ctx)) { return; }
    $pdo = $ctx['pdo'];
    $st = $pdo->prepare('SELECT a_id, b_id, xp FROM cm_couples WHERE chat_id = :c AND ended_at = 0
        ORDER BY xp DESC, since, id LIMIT ' . CM_LOVE_TOP);
    $st->execute([':c' => $ctx['chat']['chat_id']]);
    $lines = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $i => $r) {
        $lines[] = cm_t('couples_line', ['n' => $i + 1, 'a' => cm_user_name($pdo, (int)$r['a_id']),
            'b' => cm_user_name($pdo, (int)$r['b_id']), 'lvl' => cm_couple_level((int)$r['xp'])]);
    }
    cm_reply($ctx, $lines ? cm_t('couples_head') . "\n" . implode("\n", $lines) : cm_t('couples_empty'), false);
}

// --------------------------------------------------------------------------
//  Браки
// --------------------------------------------------------------------------

function cm_marriage_say(array $ctx, array $m): void {
    $pdo = $ctx['pdo'];
    cm_reply($ctx, cm_t('marry_show', ['a' => cm_user_name($pdo, $m['a_id']), 'b' => cm_user_name($pdo, $m['b_id']),
        'days' => cm_days_text((int)$ctx['now'] - $m['since'])]));
}

// «брак» без цели — свой брак. С целью (ответом или @ником): цель уже в браке
// — показать её брак, иначе — предложение.
function cm_cmd_marry(array $ctx, array $cmd): void {
    if (!cm_need3($ctx)) { return; }
    $pdo = $ctx['pdo'];
    $chatId = (int)$ctx['chat']['chat_id'];
    $me = (int)$ctx['actor'];
    $args = $cmd['args'];
    $why = null;
    $t = cm_target($pdo, $ctx['msg'], $args, $why);
    if ($t === null) {
        if ($why === 'unknown') {
            cm_reply($ctx, cm_t('target_unknown'));
            return;
        }
        $m = cm_marriage_of($pdo, $chatId, $me);
        if ($m === null) {
            cm_reply($ctx, cm_t('marry_none_me'));
            return;
        }
        cm_marriage_say($ctx, $m);
        return;
    }
    $err = cm_other_err($ctx, $t);
    if ($err !== '') {
        cm_reply($ctx, cm_t($err));
        return;
    }
    $theirs = cm_marriage_of($pdo, $chatId, (int)$t['id']);
    if ($theirs !== null) {
        cm_marriage_say($ctx, $theirs);
        return;
    }
    $mine = cm_marriage_of($pdo, $chatId, $me);
    if ($mine !== null) {
        cm_reply($ctx, cm_t('marry_have_me', ['name' => cm_user_name($pdo, cm_partner($mine, $me))]));
        return;
    }
    cm_offer_send($ctx, 'marry', (int)$t['id'], 0, cm_t('marry_offer', ['to' => $t['name'], 'from' => cm_actor_name($ctx)]));
}

function cm_offer_yes_marry(array $ctx, array $o): string {
    $pdo = $ctx['pdo'];
    if (cm_marriage_of($pdo, $o['chat_id'], $o['from_id']) !== null || cm_marriage_of($pdo, $o['chat_id'], $o['to_id']) !== null) {
        return 'marry_busy';
    }
    $pdo->prepare('INSERT INTO cm_marriages (chat_id, a_id, b_id, since, ended_at, ended_by) VALUES (:c, :a, :b, :n, 0, 0)')
        ->execute([':c' => $o['chat_id'], ':a' => $o['from_id'], ':b' => $o['to_id'], ':n' => (int)$ctx['now']]);
    cm_edit($ctx, $o['msg_id'], cm_t('marry_done', ['a' => cm_user_name($pdo, $o['from_id']), 'b' => cm_user_name($pdo, $o['to_id'])]));
    return '';
}

function cm_cmd_divorce(array $ctx, array $cmd): void {
    if (!cm_need3($ctx)) { return; }
    $pdo = $ctx['pdo'];
    $m = cm_marriage_of($pdo, (int)$ctx['chat']['chat_id'], (int)$ctx['actor']);
    if ($m === null) {
        cm_reply($ctx, cm_t('marry_none_me'));
        return;
    }
    $pdo->prepare('UPDATE cm_marriages SET ended_at = :n, ended_by = :b WHERE id = :id AND ended_at = 0')
        ->execute([':n' => (int)$ctx['now'], ':b' => (int)$ctx['actor'], ':id' => $m['id']]);
    cm_reply($ctx, cm_t('divorce_done', ['a' => cm_user_name($pdo, $m['a_id']), 'b' => cm_user_name($pdo, $m['b_id'])]), false);
}

// Последний брак человека, распавшийся меньше 3 дней назад.
function cm_marriage_recent(PDO $pdo, int $chatId, int $userId, int $now): ?array {
    $st = $pdo->prepare('SELECT * FROM cm_marriages WHERE chat_id = :c AND ended_at > :t AND (a_id = :u OR b_id = :u2)
        ORDER BY ended_at DESC, id DESC LIMIT 1');
    $st->execute([':c' => $chatId, ':t' => $now - CM_REMARRY_SECS, ':u' => $userId, ':u2' => $userId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? array_map('intval', $r) : null;
}

// «вернуть брак»: в течение 3 дней после развода, оба сейчас не в браке.
// Приглашение уходит бывшему супругу; «Принять» продолжает тот же брак.
function cm_cmd_remarry(array $ctx, array $cmd): void {
    if (!cm_need3($ctx)) { return; }
    $pdo = $ctx['pdo'];
    $chatId = (int)$ctx['chat']['chat_id'];
    $me = (int)$ctx['actor'];
    $mine = cm_marriage_of($pdo, $chatId, $me);
    if ($mine !== null) {
        cm_reply($ctx, cm_t('marry_have_me', ['name' => cm_user_name($pdo, cm_partner($mine, $me))]));
        return;
    }
    $m = cm_marriage_recent($pdo, $chatId, $me, (int)$ctx['now']);
    if ($m === null) {
        cm_reply($ctx, cm_t('remarry_none'));
        return;
    }
    $ex = cm_partner($m, $me);
    if (cm_marriage_of($pdo, $chatId, $ex) !== null) {
        cm_reply($ctx, cm_t('remarry_ex_busy', ['name' => cm_user_name($pdo, $ex)]));
        return;
    }
    cm_offer_send($ctx, 'remarry', $ex, $m['id'], cm_t('remarry_offer', ['to' => cm_user_name($pdo, $ex), 'from' => cm_actor_name($ctx)]));
}

function cm_offer_yes_remarry(array $ctx, array $o): string {
    $pdo = $ctx['pdo'];
    $st = $pdo->prepare('SELECT * FROM cm_marriages WHERE id = :id AND chat_id = :c');
    $st->execute([':id' => $o['ref_id'], ':c' => $o['chat_id']]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    $m = $r ? array_map('intval', $r) : null;
    if ($m === null || $m['ended_at'] === 0 || $m['ended_at'] <= (int)$ctx['now'] - CM_REMARRY_SECS) { return 'remarry_none'; }
    if (cm_marriage_of($pdo, $o['chat_id'], $o['from_id']) !== null || cm_marriage_of($pdo, $o['chat_id'], $o['to_id']) !== null) {
        return 'marry_busy';
    }
    $up = $pdo->prepare('UPDATE cm_marriages SET ended_at = 0, ended_by = 0 WHERE id = :id AND ended_at > 0');
    $up->execute([':id' => $m['id']]);
    if ($up->rowCount() === 0) { return 'remarry_none'; }
    cm_edit($ctx, $o['msg_id'], cm_t('remarry_done', ['a' => cm_user_name($pdo, $m['a_id']), 'b' => cm_user_name($pdo, $m['b_id'])]));
    return '';
}

function cm_cmd_marry_top(array $ctx, array $cmd): void {
    if (!cm_need3($ctx)) { return; }
    $pdo = $ctx['pdo'];
    $st = $pdo->prepare('SELECT a_id, b_id, since FROM cm_marriages WHERE chat_id = :c AND ended_at = 0
        ORDER BY since, id LIMIT ' . CM_LOVE_TOP);
    $st->execute([':c' => $ctx['chat']['chat_id']]);
    $lines = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $i => $r) {
        $lines[] = cm_t('marriages_line', ['n' => $i + 1, 'a' => cm_user_name($pdo, (int)$r['a_id']),
            'b' => cm_user_name($pdo, (int)$r['b_id']), 'days' => cm_days_text((int)$ctx['now'] - (int)$r['since'])]);
    }
    cm_reply($ctx, $lines ? cm_t('marriages_head') . "\n" . implode("\n", $lines) : cm_t('marriages_empty'), false);
}
```

- [ ] **Step 4: Команды, раздел, тексты, подключение**

Edit `public_html/api/lib/cm/parse.php` — перед строкой

```php
    'помощь'             => ['help', null, 0, true, false],
```

вставить

```php
    'встречаться'        => ['couple_ask', 'отношения', 0, false, true],
    'расстаться'         => ['couple_end', 'отношения', 0, true, false],
    'пара'               => ['couple_show', 'отношения', 0, false, false],
    'пары'               => ['couple_top', 'отношения', 0, true, false],
    'брак'               => ['marry', 'отношения', 0, false, false],
    'развод'             => ['divorce', 'отношения', 0, true, false],
    'вернуть брак'       => ['remarry', 'отношения', 0, true, false],
    'браки'              => ['marry_top', 'отношения', 0, true, false],
```

Edit `public_html/api/lib/cm/parse.php`, в `CM_BARE_ARGS` — фрагмент

```php
    'duel'        => '~^$~',
];
```

заменить на

```php
    'duel'        => '~^$~',
    'couple_ask'  => '~^$~',
    'couple_show' => '~^$~',
    'marry'       => '~^$~',
];
```

Edit `public_html/api/lib/cm/ranks.php`: строку `                     'репутация', 'награды', 'анкета', 'игры', 'рп'];` заменить на `                     'репутация', 'награды', 'анкета', 'игры', 'рп', 'отношения'];`.

Edit `public_html/api/lib/cm/texts.php` — после строки

```php
    'reason_duel'      => 'проигрыш в дуэли',
```

добавить

```php
    // пары и браки
    'couple_offer'     => '💞 {to}, {from} предлагает встречаться. Согласны?',
    'couple_done'      => '💞 {a} и {b} теперь пара!',
    'couple_have_me'   => 'У вас уже есть пара: {name}.',
    'couple_have_them' => 'У {name} уже есть пара.',
    'couple_busy'      => 'Не вышло: кто-то из вас уже в паре.',
    'couple_none_me'   => 'У вас нет пары.',
    'couple_none'      => '{name} ни с кем не встречается.',
    'couple_end'       => '💔 {a} и {b} расстались.',
    'couple_show'      => '💞 {a} и {b} вместе {days}, уровень {lvl} (опыт {xp})',
    'couples_head'     => '💞 Пары чата:',
    'couples_line'     => '{n}. {a} и {b} — уровень {lvl}',
    'couples_empty'    => 'Пар пока нет.',
    'marry_offer'      => '💍 {to}, {from} делает вам предложение. Согласны?',
    'marry_done'       => '💍 {a} и {b} теперь в браке!',
    'marry_have_me'    => 'Вы уже в браке с {name}.',
    'marry_busy'       => 'Не вышло: кто-то из вас уже в браке.',
    'marry_none_me'    => 'Вы не в браке.',
    'marry_show'       => '💍 {a} и {b} в браке {days}',
    'divorce_done'     => '💔 {a} и {b} развелись. Передумали — «вернуть брак» в течение 3 дней.',
    'remarry_offer'    => '💍 {to}, {from} предлагает вернуть брак. Согласны?',
    'remarry_none'     => 'Вернуть можно только брак, распавшийся меньше 3 дней назад.',
    'remarry_ex_busy'  => '{name} уже в другом браке.',
    'remarry_done'     => '💍 {a} и {b} снова в браке!',
    'marriages_head'   => '💍 Самые долгие браки:',
    'marriages_line'   => '{n}. {a} и {b} — {days}',
    'marriages_empty'  => 'Браков пока нет.',
```

Edit `public_html/api/lib/cm/cm.php`: строку `          'fun', 'offers', 'dice', 'duel', 'router', 'cron'] as $cm_part) {` заменить на `          'fun', 'offers', 'dice', 'duel', 'love', 'router', 'cron'] as $cm_part) {`.

- [ ] **Step 5: Тест проходит, остальное цело**

Run: `/c/xampp/php/php.exe tests/cm_love_test.php`
Expected: `70 assertions, 0 failures`.

Run:
```bash
for t in tests/cm_*_test.php tests/php74_compat_test.php; do /c/xampp/php/php.exe -d extension=gd "$t" > /dev/null 2>&1 || echo "FAIL $t"; done; echo done
```
Expected: одна строка `done`.

- [ ] **Step 6: Commit**

```bash
git add public_html/api/lib/cm/love.php tests/cm_love_test.php public_html/api/lib/cm/parse.php public_html/api/lib/cm/ranks.php public_html/api/lib/cm/texts.php public_html/api/lib/cm/cm.php
git commit -m "feat(cm): пары и браки, возврат брака за 3 дня" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git show --stat HEAD
```
Expected: 6 файлов, без `Bin`.

---

### Task 6: Кланы

**Files:**
- Create: `public_html/api/lib/cm/clans.php`
- Modify: `public_html/api/lib/cm/parse.php`, `ranks.php`, `texts.php`, `cm.php`
- Test: `tests/cm_clans_test.php`

**Interfaces:**
- Consumes: `cm_need3` (Task 1); `cm_offer_send`, `cm_edit`, `cm_offer_callback` (вызывает `cm_offer_yes_clan`), `cm_press` в тестах (Task 3); `cm_actor_name`, `cm_other_err` (Task 5); этап 2: `cm_day_from(int $now, int $days): int`, `cm_num`; этап 1: `cm_need_target`, `cm_utf16_len(string $s): int`, `cm_has_link(array $msg, array $domains): bool`, `cm_has_mat(string $text, array $rules): bool`, `chat_filter_compile`, `CM_MAT_WORDS`, `cm_user_name`.
- Produces:
  - `CM_CLAN_MAX` (100), `CM_CLAN_ABOUT_MAX` (200), `CM_CLAN_CALL_MAX` (30), `CM_CLAN_CALL_GAP` (600), `CM_CLAN_DAYS` (7), `CM_CLAN_TOP` (10)
  - `cm_clan_name(string $raw): string` ('' — не подходит), `cm_clan_decode(array $r): array`, `cm_clan_get(PDO $pdo, int $id): ?array`, `cm_clan_by_name(PDO $pdo, int $chatId, string $name): ?array`
  - `cm_clan_of(PDO $pdo, int $chatId, int $userId): ?array` — клан человека (`id, chat_id, name, name_key, owner_id, about, is_open, called_at, created_at`) — **берёт Task 7 для профиля**
  - `cm_clan_size(PDO $pdo, int $clanId): int`, `cm_clan_week(PDO $pdo, array $clan, int $now): int`, `cm_clan_text_ok(string $s): bool`, `cm_clan_add_member(PDO $pdo, array $clan, int $userId, int $now): bool`, `cm_clan_owned(array $ctx): ?array`, `cm_clan_card(array $ctx, array $c): string`
  - `cm_offer_yes_clan`; обработчики `cm_cmd_clan_add`, `cm_cmd_clan_del`, `cm_cmd_clan_show`, `cm_cmd_clan_top`, `cm_cmd_clan_join`, `cm_cmd_clan_leave`, `cm_cmd_clan_invite`, `cm_cmd_clan_kick`, `cm_cmd_clan_open`, `cm_cmd_clan_about`, `cm_cmd_clan_call`
  - раздел `кланы`
  - Тексты: `clan_usage`, `clan_join_usage`, `clan_text_bad`, `clan_taken`, `clan_have_me`, `clan_created`, `clan_none_me`, `clan_missing`, `clan_owner_only`, `clan_deleted`, `clan_info`, `clan_about_line`, `clan_state_open`, `clan_state_closed`, `clan_closed`, `clan_full`, `clan_joined`, `clan_left`, `clan_owner_stays`, `clan_kicked`, `clan_not_member`, `clan_them_in`, `clan_offer`, `clan_opened`, `clan_shut`, `clan_about_usage`, `clan_about_done`, `clan_call`, `clan_call_wait`, `clan_call_empty`, `clans_head`, `clans_line`, `clans_empty`, `clan_gone`, `clan_busy`, `clan_full_short`

- [ ] **Step 1: Падающий тест**

Write `tests/cm_clans_test.php`:

```php
<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';

// Кланы (этап 3): api/lib/cm/clans.php.

function cl_people(PDO $pdo): void {
    cm_person($pdo, 7, 'user7', 'U7');
    cm_person($pdo, 20, 'petya', 'Петя');
    cm_person($pdo, 21, 'kolya', 'Коля');
    cm_person($pdo, 22, 'olya', 'Оля');
    cm_person($pdo, 30, 'user30', 'U30');
}

function cl_do(PDO $pdo, array &$log, int $actor, string $text, int $now = CM_NOW): void {
    cm_do(cm_ctx_for($pdo, $log, $actor, '', [], [], $now), $text);
}

function cl_members(PDO $pdo, int $clanId): array {
    $st = $pdo->prepare('SELECT user_id FROM cm_clan_members WHERE clan_id = :k ORDER BY user_id');
    $st->execute([':k' => $clanId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

test('название: 2–32 символа, буквы, цифры, пробел, дефис', function () {
    assert_eq('Волки Севера', cm_clan_name('  Волки   Севера '));
    assert_eq('Team-42', cm_clan_name('Team-42'));
    foreach (['А', str_repeat('я', 33), 'Волки!', '-Волки', 'Волки-', 'evil.ru', ''] as $bad) {
        assert_eq('', cm_clan_name($bad), $bad);
    }
});

test('+клан: глава — создатель, имя уникально без учёта регистра и ё', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cl_people($pdo);
    cl_do($pdo, $log, 7, '+клан  Волки   Севера ');
    assert_eq('🛡 Клан «Волки Севера» создан. Глава — U7.', cm_last_text($log));
    assert_eq(0, (int)$pdo->query('SELECT COUNT(*) FROM cm_queue')->fetchColumn(), 'не удаляется');
    $c = cm_clan_of($pdo, CM_CHAT, 7);
    assert_eq(['Волки Севера', 'волки севера', 7, 1], [$c['name'], $c['name_key'], $c['owner_id'], $c['is_open']]);
    cl_do($pdo, $log, 20, '+клан ВОЛКИ СЕВЕРА');
    assert_eq('Клан «ВОЛКИ СЕВЕРА» уже есть.', cm_last_text($log));
    cl_do($pdo, $log, 20, '+клан Ёжики');
    cl_do($pdo, $log, 21, '+клан ежики');
    assert_eq('Клан «ежики» уже есть.', cm_last_text($log));
    cl_do($pdo, $log, 7, '+клан Другой');
    assert_eq('Вы уже в клане «Волки Севера».', cm_last_text($log));
    cl_do($pdo, $log, 21, '+клан evil.ru');
    assert_eq(cm_t('clan_usage'), cm_last_text($log));
    cl_do($pdo, $log, 21, '+клан сука');
    assert_eq(cm_t('clan_text_bad'), cm_last_text($log));
    assert_eq(2, (int)$pdo->query('SELECT COUNT(*) FROM cm_clans')->fetchColumn());
});

test('вступить: открытый — сразу, закрытый — нужно приглашение; «в клан» можно писать', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cl_people($pdo);
    cl_do($pdo, $log, 7, '+клан Волки');
    cl_do($pdo, $log, 20, 'вступить Волки');
    assert_eq('🛡 U20 теперь в клане «Волки».', cm_last_text($log));
    cl_do($pdo, $log, 21, 'вступить в клан волки');
    assert_eq('🛡 U21 теперь в клане «Волки».', cm_last_text($log));
    assert_eq([7, 20, 21], cl_members($pdo, 1));
    cl_do($pdo, $log, 20, 'вступить Волки');
    assert_eq('Вы уже в клане «Волки».', cm_last_text($log));
    cl_do($pdo, $log, 7, 'клан закрыт');
    assert_eq('Клан «Волки» закрыт: вход только по приглашению.', cm_last_text($log));
    cl_do($pdo, $log, 22, 'вступить Волки');
    assert_eq('Клан «Волки» закрыт: нужно приглашение главы.', cm_last_text($log));
    cl_do($pdo, $log, 7, 'клан открыт');
    assert_eq('Клан «Волки» открыт: вступить может любой.', cm_last_text($log));
    cl_do($pdo, $log, 22, 'вступить Медведи');
    assert_eq('Клана «Медведи» нет.', cm_last_text($log));
    cl_do($pdo, $log, 22, 'вступить');
    assert_eq(cm_t('clan_join_usage'), cm_last_text($log));
    cl_do($pdo, $log, 21, 'клан закрыт');
    assert_eq(cm_t('clan_owner_only'), cm_last_text($log));
    cl_do($pdo, $log, 30, '+клан Клан Волков');
    cl_do($pdo, $log, 22, 'вступить Клан Волков');
    assert_eq('🛡 U22 теперь в клане «Клан Волков».', cm_last_text($log), 'имя, начатое со слова «клан»');
});

test('пригласить: только глава; приглашение → вступление; занятых и полных — нет', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cl_people($pdo);
    cl_do($pdo, $log, 7, '+клан Волки');
    cl_do($pdo, $log, 7, 'клан закрыт');
    cl_do($pdo, $log, 30, 'пригласить @olya');
    assert_eq(cm_t('clan_none_me'), cm_last_text($log));
    cl_do($pdo, $log, 7, 'пригласить @olya');
    assert_eq('🛡 Оля, U7 зовёт вас в клан «Волки».', cm_last_text($log));
    $o = cm_offer_get($pdo, 1);
    assert_eq(['clan', 22, 1, CM_NOW + 300], [$o['kind'], $o['to_id'], $o['ref_id'], $o['expires_at']]);
    assert_eq(cm_t('offer_accepted'), cm_press($pdo, $log, 22, 'cm:p:1:y'));
    $e = cm_calls($log, 'editMessageText');
    assert_eq('🛡 Оля теперь в клане «Волки».', $e[count($e) - 1][1]['text']);
    assert_eq([7, 22], cl_members($pdo, 1));
    cl_do($pdo, $log, 22, 'пригласить @petya');
    assert_eq(cm_t('clan_owner_only'), cm_last_text($log));
    cl_do($pdo, $log, 7, 'пригласить @olya');
    assert_eq('Оля уже в клане.', cm_last_text($log));
    cl_do($pdo, $log, 7, 'пригласить @kolya');
    cl_do($pdo, $log, 21, '+клан Медведи');
    assert_eq(cm_t('clan_busy'), cm_press($pdo, $log, 21, 'cm:p:2:y'), 'пока ждал — вступил в другой');
    $ins = $pdo->prepare('INSERT INTO cm_clan_members (chat_id, user_id, clan_id, joined_at) VALUES (:c, :u, 1, 0)');
    for ($u = 1000; $u < 1098; $u++) { $ins->execute([':c' => CM_CHAT, ':u' => $u]); }
    assert_eq(100, cm_clan_size($pdo, 1));
    cl_do($pdo, $log, 7, 'пригласить @petya');
    assert_eq('В клане «Волки» уже 100 участников.', cm_last_text($log));
    cl_do($pdo, $log, 7, 'клан открыт');
    cl_do($pdo, $log, 20, 'вступить Волки');
    assert_eq('В клане «Волки» уже 100 участников.', cm_last_text($log));
});

test('исключить и покинуть клан; глава выйти не может', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cl_people($pdo);
    cl_do($pdo, $log, 7, '+клан Волки');
    cl_do($pdo, $log, 20, 'вступить Волки');
    cl_do($pdo, $log, 21, 'вступить Волки');
    cl_do($pdo, $log, 7, 'исключить @petya');
    assert_eq('Петя больше не в клане «Волки».', cm_last_text($log));
    cl_do($pdo, $log, 7, 'исключить @petya');
    assert_eq('Петя не в вашем клане.', cm_last_text($log));
    cl_do($pdo, $log, 21, 'исключить @user7');
    assert_eq(cm_t('clan_owner_only'), cm_last_text($log));
    $self = ['reply_to_message' => ['message_id' => 70, 'from' => ['id' => 7, 'first_name' => 'U7']]];
    cm_do(cm_ctx_for($pdo, $log, 7, '', $self), 'исключить');
    assert_eq(cm_t('target_self'), cm_last_text($log));
    cl_do($pdo, $log, 21, 'покинуть клан');
    assert_eq('U21 покидает клан «Волки».', cm_last_text($log));
    cl_do($pdo, $log, 21, 'покинуть клан');
    assert_eq(cm_t('clan_none_me'), cm_last_text($log));
    cl_do($pdo, $log, 7, 'покинуть клан');
    assert_eq(cm_t('clan_owner_stays'), cm_last_text($log));
    assert_eq([7], cl_members($pdo, 1));
});

test('клан и кланы: карточка, описание, активность за неделю, топ', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cl_people($pdo);
    cl_do($pdo, $log, 7, '+клан Волки');
    cl_do($pdo, $log, 20, 'вступить Волки');
    cl_do($pdo, $log, 21, '+клан Медведи');
    $ins = $pdo->prepare('INSERT INTO cm_stats (chat_id, user_id, day, msgs) VALUES (:c, :u, :d, :m)');
    foreach ([[7, 20260921, 100], [20, 20260915, 34], [20, 20260914, 1000], [21, 20260921, 50], [30, 20260921, 999]] as $r) {
        $ins->execute([':c' => CM_CHAT, ':u' => $r[0], ':d' => $r[1], ':m' => $r[2]]);
    }
    cm_do(cm_ctx_for($pdo, $log, 7), "клан описание Лучшие   трейдеры\nи друзья");
    assert_eq(cm_t('clan_about_done'), cm_last_text($log));
    cl_do($pdo, $log, 30, '!клан волки');
    $card = "🛡 Клан «Волки»\nГлава: U7\nУчастников: 2/100 · открыт\nАктивность за неделю: 134\nО клане: Лучшие трейдеры и друзья";
    assert_eq($card, cm_last_text($log));
    cl_do($pdo, $log, 20, 'клан');
    assert_eq($card, cm_last_text($log), 'свой клан');
    cl_do($pdo, $log, 30, 'клан');
    assert_eq(cm_t('clan_none_me'), cm_last_text($log));
    cl_do($pdo, $log, 30, '!клан Тигры');
    assert_eq('Клана «Тигры» нет.', cm_last_text($log));
    cl_do($pdo, $log, 30, 'кланы');
    assert_eq("🛡 Кланы чата (активность за неделю):\n1. Волки — 134 · 2 чел.\n2. Медведи — 50 · 1 чел.", cm_last_text($log));
    cl_do($pdo, $log, 21, 'клан закрыт');
    cl_do($pdo, $log, 30, '!клан Медведи');
    assert_eq("🛡 Клан «Медведи»\nГлава: Коля\nУчастников: 1/100 · вход по приглашению\nАктивность за неделю: 50", cm_last_text($log));
    foreach (['клан описание', 'клан описание ' . str_repeat('я', 201)] as $bad) {
        cl_do($pdo, $log, 7, $bad);
        assert_eq(cm_t('clan_about_usage'), cm_last_text($log), $bad);
    }
    cl_do($pdo, $log, 7, 'клан описание заходите на evil.ru');
    assert_eq(cm_t('clan_text_bad'), cm_last_text($log));
    cl_do($pdo, $log, 20, 'клан описание моё');
    assert_eq(cm_t('clan_owner_only'), cm_last_text($log));
    cm_do(cm_ctx_for(cm_test_pdo(), $log, 7), 'кланы');
    assert_eq(cm_t('clans_empty'), cm_last_text($log));
});

test('созвать клан: скрытые упоминания, до 30 человек, ушедших нет, раз в 10 минут', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cl_people($pdo);
    cl_do($pdo, $log, 7, '+клан Волки');
    cl_do($pdo, $log, 20, 'вступить Волки');
    cl_do($pdo, $log, 21, 'вступить Волки', CM_NOW + 1);
    cl_do($pdo, $log, 22, 'вступить Волки', CM_NOW + 2);
    cm_member_set($pdo, CM_CHAT, 22, ['left_at' => CM_NOW]);
    cl_do($pdo, $log, 7, 'созвать клан', CM_NOW + 100);
    $m = cm_calls($log, 'sendMessage');
    $p = $m[count($m) - 1][1];
    $head = "📣 U7 созывает клан «Волки»:\n";
    assert_eq($head . 'Петя, Коля', $p['text']);
    assert_eq(29, cm_utf16_len($head), '📣 — две единицы UTF-16');
    assert_eq([['type' => 'text_mention', 'offset' => 29, 'length' => 4, 'user' => ['id' => 20, 'is_bot' => false, 'first_name' => 'Петя']],
               ['type' => 'text_mention', 'offset' => 35, 'length' => 4, 'user' => ['id' => 21, 'is_bot' => false, 'first_name' => 'Коля']]],
        $p['entities']);
    cl_do($pdo, $log, 7, 'созвать клан', CM_NOW + 400);
    assert_eq('Созывать клан можно раз в 10 минут. Следующий раз — через 5 мин.', cm_last_text($log));
    cl_do($pdo, $log, 7, 'созвать клан', CM_NOW + 699);
    assert_eq('Созывать клан можно раз в 10 минут. Следующий раз — через 1 мин.', cm_last_text($log));
    cl_do($pdo, $log, 7, 'созвать клан', CM_NOW + 700);
    assert_eq($head . 'Петя, Коля', cm_last_text($log), 'через 10 минут — снова можно');
    cl_do($pdo, $log, 20, 'созвать клан', CM_NOW + 2000);
    assert_eq(cm_t('clan_owner_only'), cm_last_text($log));
    $ins = $pdo->prepare('INSERT INTO cm_clan_members (chat_id, user_id, clan_id, joined_at) VALUES (:c, :u, 1, :j)');
    for ($u = 1000; $u < 1040; $u++) { $ins->execute([':c' => CM_CHAT, ':u' => $u, ':j' => CM_NOW + 10]); }
    cl_do($pdo, $log, 7, 'созвать клан', CM_NOW + 3000);
    $m = cm_calls($log, 'sendMessage');
    assert_eq(30, count($m[count($m) - 1][1]['entities']));
    cl_do($pdo, $log, 30, '+клан Одиночки');
    cl_do($pdo, $log, 30, 'созвать клан');
    assert_eq(cm_t('clan_call_empty'), cm_last_text($log));
});

test('-клан: глава распускает, участники выходят, имя свободно; приглашение в него неактуально', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cl_people($pdo);
    cl_do($pdo, $log, 7, '+клан Волки');
    cl_do($pdo, $log, 20, 'вступить Волки');
    cl_do($pdo, $log, 7, 'пригласить @kolya');
    cl_do($pdo, $log, 20, '-клан');
    assert_eq(cm_t('clan_owner_only'), cm_last_text($log));
    cl_do($pdo, $log, 7, '-клан');
    assert_eq('💥 Клан «Волки» распущен.', cm_last_text($log));
    assert_eq([0, 0], [(int)$pdo->query('SELECT COUNT(*) FROM cm_clans')->fetchColumn(),
        (int)$pdo->query('SELECT COUNT(*) FROM cm_clan_members')->fetchColumn()]);
    assert_eq(cm_t('clan_gone'), cm_press($pdo, $log, 21, 'cm:p:1:y'));
    cl_do($pdo, $log, 20, '+клан Волки');
    assert_eq('🛡 Клан «Волки» создан. Глава — U20.', cm_last_text($log));
});

test('без префикса — по правилам команд; дк кланы 6; без таблиц — «ещё не настроено»', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cl_people($pdo);
    $reply = ['reply_to_message' => ['message_id' => 70, 'from' => ['id' => 20, 'first_name' => 'Петя']]];
    $run = function (string $text, array $extra = []) use ($pdo, &$log): bool {
        return cm_run_command(cm_ctx_for($pdo, $log, 7, $text, $extra), cm_parse($text, CM_BOT));
    };
    foreach (['+клан Волки', 'клан', 'кланы', 'клан открыт', 'созвать клан', 'пригласить @petya', 'покинуть клан', 'вступить Медведи'] as $t) {
        assert_eq(true, $run($t), $t);
    }
    assert_eq(true, $run('исключить', $reply));
    foreach (['клан крутой у нас', 'кланы рулят', 'вступить в спор?', 'клан открыт для всех', 'созвать клан срочно'] as $t) {
        assert_eq(false, $run($t), $t);
    }
    assert_eq(false, $run('пригласить бы его', $reply));
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), 'дк кланы 6');
    assert_eq(true, $run('!кланы'));
    assert_eq(cm_t('cmd_off'), cm_last_text($log));
    $pdo = cm_test_pdo();
    $pdo->exec('DROP TABLE cm_offers');
    cm_do(cm_ctx_for($pdo, $log, 7), 'кланы');
    assert_eq(cm_t('not_ready2'), cm_last_text($log));
});

run_tests();
```

- [ ] **Step 2: Убедиться, что тест падает**

Run: `/c/xampp/php/php.exe tests/cm_clans_test.php`
Expected: FAIL — `ERROR - название: …: Call to undefined function cm_clan_name()`, дальше `не команда: +клан …`.

- [ ] **Step 3: Кланы**

Write `public_html/api/lib/cm/clans.php`:

```php
<?php
// Кланы (этап 3). Человек в чате — не больше чем в одном клане. Глава —
// создатель; передачи главенства нет, и выйти глава не может — только
// распустить клан («-клан»). Управляет кланом только глава. Рейтинг —
// сообщения участников за 7 дней (cm_stats этапа 2), экономики нет. Уход из
// чата из клана не выводит, но созыв таких не зовёт.

const CM_CLAN_MAX       = 100;
const CM_CLAN_ABOUT_MAX = 200;
const CM_CLAN_CALL_MAX  = 30;
const CM_CLAN_CALL_GAP  = 600;
const CM_CLAN_DAYS      = 7;
const CM_CLAN_TOP       = 10;

// «  Волки   Севера » → «Волки Севера»; '' — не подходит: 2–32 символа,
// буквы, цифры, пробел, дефис, по краям — буква или цифра.
function cm_clan_name(string $raw): string {
    $n = trim((string)preg_replace('~\s+~u', ' ', $raw));
    $len = mb_strlen($n);
    if ($len < 2 || $len > 32 || !preg_match('~^[\p{L}\p{N}](?:[\p{L}\p{N} -]*[\p{L}\p{N}])?$~u', $n)) { return ''; }
    return $n;
}

function cm_clan_decode(array $r): array {
    foreach (['id', 'chat_id', 'owner_id', 'is_open', 'called_at', 'created_at'] as $k) { $r[$k] = (int)$r[$k]; }
    foreach (['name', 'name_key', 'about'] as $k) { $r[$k] = (string)$r[$k]; }
    return $r;
}

function cm_clan_get(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM cm_clans WHERE id = :id');
    $st->execute([':id' => $id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? cm_clan_decode($r) : null;
}

// Клан по названию — без учёта регистра и ё/е.
function cm_clan_by_name(PDO $pdo, int $chatId, string $name): ?array {
    $st = $pdo->prepare('SELECT * FROM cm_clans WHERE chat_id = :c AND name_key = :k');
    $st->execute([':c' => $chatId, ':k' => cm_norm($name)]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? cm_clan_decode($r) : null;
}

// Клан, в котором человек состоит; null — ни в каком.
function cm_clan_of(PDO $pdo, int $chatId, int $userId): ?array {
    $st = $pdo->prepare('SELECT c.* FROM cm_clan_members m JOIN cm_clans c ON c.id = m.clan_id
        WHERE m.chat_id = :c AND m.user_id = :u');
    $st->execute([':c' => $chatId, ':u' => $userId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? cm_clan_decode($r) : null;
}

function cm_clan_size(PDO $pdo, int $clanId): int {
    $st = $pdo->prepare('SELECT COUNT(*) FROM cm_clan_members WHERE clan_id = :k');
    $st->execute([':k' => $clanId]);
    return (int)$st->fetchColumn();
}

// Сообщения участников клана за неделю, сегодня включительно.
function cm_clan_week(PDO $pdo, array $clan, int $now): int {
    $st = $pdo->prepare('SELECT COALESCE(SUM(s.msgs), 0) FROM cm_clan_members m
        JOIN cm_stats s ON s.chat_id = m.chat_id AND s.user_id = m.user_id AND s.day >= :d
        WHERE m.clan_id = :k');
    $st->execute([':d' => cm_day_from($now, CM_CLAN_DAYS), ':k' => $clan['id']]);
    return (int)$st->fetchColumn();
}

// Название и описание — без ссылок и мата: их видно всем, а сообщение-команду
// фильтры уже не проверяют.
function cm_clan_text_ok(string $s): bool {
    return !cm_has_link(['text' => $s], []) && !cm_has_mat($s, chat_filter_compile(CM_MAT_WORDS));
}

// В клан. false — человек уже в каком-то клане (ключ chat_id + user_id).
function cm_clan_add_member(PDO $pdo, array $clan, int $userId, int $now): bool {
    try {
        $pdo->prepare('INSERT INTO cm_clan_members (chat_id, user_id, clan_id, joined_at) VALUES (:c, :u, :k, :n)')
            ->execute([':c' => $clan['chat_id'], ':u' => $userId, ':k' => $clan['id'], ':n' => $now]);
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

// Клан, которым правит автор контекста; иначе — подсказка и null.
function cm_clan_owned(array $ctx): ?array {
    $c = cm_clan_of($ctx['pdo'], (int)$ctx['chat']['chat_id'], (int)$ctx['actor']);
    if ($c === null) {
        cm_reply($ctx, cm_t('clan_none_me'));
        return null;
    }
    if ($c['owner_id'] !== (int)$ctx['actor']) {
        cm_reply($ctx, cm_t('clan_owner_only'));
        return null;
    }
    return $c;
}

function cm_clan_card(array $ctx, array $c): string {
    $pdo = $ctx['pdo'];
    return cm_t('clan_info', ['name' => $c['name'], 'owner' => cm_user_name($pdo, $c['owner_id']),
            'n' => cm_clan_size($pdo, $c['id']), 'max' => CM_CLAN_MAX,
            'state' => cm_t($c['is_open'] ? 'clan_state_open' : 'clan_state_closed'),
            'msgs' => cm_num(cm_clan_week($pdo, $c, (int)$ctx['now']))])
        . ($c['about'] !== '' ? cm_t('clan_about_line', ['about' => $c['about']]) : '');
}

// --------------------------------------------------------------------------
//  Команды
// --------------------------------------------------------------------------

function cm_cmd_clan_add(array $ctx, array $cmd): void {
    if (!cm_need3($ctx)) { return; }
    $name = cm_clan_name($cmd['args']);
    if ($name === '') {
        cm_reply($ctx, cm_t('clan_usage'));
        return;
    }
    if (!cm_clan_text_ok($name)) {
        cm_reply($ctx, cm_t('clan_text_bad'));
        return;
    }
    $pdo = $ctx['pdo'];
    $chatId = (int)$ctx['chat']['chat_id'];
    $me = (int)$ctx['actor'];
    $mine = cm_clan_of($pdo, $chatId, $me);
    if ($mine !== null) {
        cm_reply($ctx, cm_t('clan_have_me', ['name' => $mine['name']]));
        return;
    }
    $now = (int)$ctx['now'];
    try {
        $pdo->prepare("INSERT INTO cm_clans (chat_id, name, name_key, owner_id, about, is_open, called_at, created_at)
                       VALUES (:c, :n, :k, :o, '', 1, 0, :t)")
            ->execute([':c' => $chatId, ':n' => $name, ':k' => cm_norm($name), ':o' => $me, ':t' => $now]);
    } catch (PDOException $e) {
        cm_reply($ctx, cm_t('clan_taken', ['name' => $name]));   // уникальный ключ (chat_id, name_key)
        return;
    }
    cm_clan_add_member($pdo, cm_clan_get($pdo, (int)$pdo->lastInsertId()), $me, $now);
    cm_reply($ctx, cm_t('clan_created', ['name' => $name, 'owner' => cm_actor_name($ctx)]), false);
}

// «-клан»: глава распускает клан, участники выходят.
function cm_cmd_clan_del(array $ctx, array $cmd): void {
    if (!cm_need3($ctx)) { return; }
    $c = cm_clan_owned($ctx);
    if ($c === null) { return; }
    $pdo = $ctx['pdo'];
    $pdo->prepare('DELETE FROM cm_clan_members WHERE clan_id = :k')->execute([':k' => $c['id']]);
    $pdo->prepare('DELETE FROM cm_clans WHERE id = :k')->execute([':k' => $c['id']]);
    cm_reply($ctx, cm_t('clan_deleted', ['name' => $c['name']]), false);
}

// «клан» — свой; «!клан Название» — любой.
function cm_cmd_clan_show(array $ctx, array $cmd): void {
    if (!cm_need3($ctx)) { return; }
    $pdo = $ctx['pdo'];
    $chatId = (int)$ctx['chat']['chat_id'];
    $raw = trim($cmd['args']);
    if ($raw === '') {
        $c = cm_clan_of($pdo, $chatId, (int)$ctx['actor']);
        if ($c === null) {
            cm_reply($ctx, cm_t('clan_none_me'));
            return;
        }
    } else {
        $c = cm_clan_by_name($pdo, $chatId, cm_clan_name($raw));
        if ($c === null) {
            cm_reply($ctx, cm_t('clan_missing', ['name' => mb_substr($raw, 0, 32)]));
            return;
        }
    }
    cm_reply($ctx, cm_clan_card($ctx, $c), false);
}

// Топ-10 по сообщениям участников за неделю. LIMIT — числом в строке.
function cm_cmd_clan_top(array $ctx, array $cmd): void {
    if (!cm_need3($ctx)) { return; }
    $pdo = $ctx['pdo'];
    $st = $pdo->prepare('SELECT c.id, c.name, COALESCE(SUM(s.msgs), 0) AS n,
            (SELECT COUNT(*) FROM cm_clan_members mm WHERE mm.clan_id = c.id) AS members
        FROM cm_clans c
        LEFT JOIN cm_clan_members m ON m.clan_id = c.id
        LEFT JOIN cm_stats s ON s.chat_id = m.chat_id AND s.user_id = m.user_id AND s.day >= :d
        WHERE c.chat_id = :c GROUP BY c.id, c.name ORDER BY n DESC, c.id LIMIT ' . CM_CLAN_TOP);
    $st->execute([':d' => cm_day_from((int)$ctx['now'], CM_CLAN_DAYS), ':c' => $ctx['chat']['chat_id']]);
    $lines = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $i => $r) {
        $lines[] = cm_t('clans_line', ['n' => $i + 1, 'name' => (string)$r['name'], 'msgs' => cm_num((int)$r['n']),
            'members' => (int)$r['members']]);
    }
    cm_reply($ctx, $lines ? cm_t('clans_head') . "\n" . implode("\n", $lines) : cm_t('clans_empty'), false);
}

// «вступить Волки», «вступить в клан Волки». Закрытый — только по приглашению.
function cm_cmd_clan_join(array $ctx, array $cmd): void {
    if (!cm_need3($ctx)) { return; }
    $pdo = $ctx['pdo'];
    $chatId = (int)$ctx['chat']['chat_id'];
    $raw = trim($cmd['args']);
    $name = cm_clan_name($raw);
    $c = $name === '' ? null : cm_clan_by_name($pdo, $chatId, $name);
    if ($c === null) {
        $name = cm_clan_name((string)preg_replace('~^(?:в\s+)?клан\s+~iu', '', $raw));
        $c = $name === '' ? null : cm_clan_by_name($pdo, $chatId, $name);
    }
    if ($name === '') {
        cm_reply($ctx, cm_t('clan_join_usage'));
        return;
    }
    if ($c === null) {
        cm_reply($ctx, cm_t('clan_missing', ['name' => $name]));
        return;
    }
    $me = (int)$ctx['actor'];
    $mine = cm_clan_of($pdo, $chatId, $me);
    if ($mine !== null) {
        cm_reply($ctx, cm_t('clan_have_me', ['name' => $mine['name']]));
        return;
    }
    if (!$c['is_open']) {
        cm_reply($ctx, cm_t('clan_closed', ['name' => $c['name']]));
        return;
    }
    if (cm_clan_size($pdo, $c['id']) >= CM_CLAN_MAX) {
        cm_reply($ctx, cm_t('clan_full', ['name' => $c['name'], 'max' => CM_CLAN_MAX]));
        return;
    }
    if (!cm_clan_add_member($pdo, $c, $me, (int)$ctx['now'])) {
        cm_reply($ctx, cm_t('clan_busy'));
        return;
    }
    cm_reply($ctx, cm_t('clan_joined', ['name' => cm_actor_name($ctx), 'clan' => $c['name']]), false);
}

function cm_cmd_clan_leave(array $ctx, array $cmd): void {
    if (!cm_need3($ctx)) { return; }
    $pdo = $ctx['pdo'];
    $me = (int)$ctx['actor'];
    $c = cm_clan_of($pdo, (int)$ctx['chat']['chat_id'], $me);
    if ($c === null) {
        cm_reply($ctx, cm_t('clan_none_me'));
        return;
    }
    if ($c['owner_id'] === $me) {
        cm_reply($ctx, cm_t('clan_owner_stays'));
        return;
    }
    $pdo->prepare('DELETE FROM cm_clan_members WHERE chat_id = :c AND user_id = :u')
        ->execute([':c' => $c['chat_id'], ':u' => $me]);
    cm_reply($ctx, cm_t('clan_left', ['name' => cm_actor_name($ctx), 'clan' => $c['name']]), false);
}

// «пригласить @ник» (глава) → приглашение → вступление, даже в закрытый клан.
function cm_cmd_clan_invite(array $ctx, array $cmd): void {
    if (!cm_need3($ctx)) { return; }
    $c = cm_clan_owned($ctx);
    if ($c === null) { return; }
    $args = $cmd['args'];
    $t = cm_need_target($ctx, $args);
    if ($t === null) { return; }
    $err = cm_other_err($ctx, $t);
    if ($err !== '') {
        cm_reply($ctx, cm_t($err));
        return;
    }
    $pdo = $ctx['pdo'];
    if (cm_clan_of($pdo, $c['chat_id'], (int)$t['id']) !== null) {
        cm_reply($ctx, cm_t('clan_them_in', ['name' => $t['name']]));
        return;
    }
    if (cm_clan_size($pdo, $c['id']) >= CM_CLAN_MAX) {
        cm_reply($ctx, cm_t('clan_full', ['name' => $c['name'], 'max' => CM_CLAN_MAX]));
        return;
    }
    cm_offer_send($ctx, 'clan', (int)$t['id'], $c['id'],
        cm_t('clan_offer', ['to' => $t['name'], 'from' => cm_actor_name($ctx), 'clan' => $c['name']]));
}

// «Принять» приглашение в клан: клан ещё есть, человек ни в каком, место есть.
function cm_offer_yes_clan(array $ctx, array $o): string {
    $pdo = $ctx['pdo'];
    $c = cm_clan_get($pdo, $o['ref_id']);
    if ($c === null || $c['chat_id'] !== $o['chat_id']) { return 'clan_gone'; }
    if (cm_clan_of($pdo, $o['chat_id'], $o['to_id']) !== null) { return 'clan_busy'; }
    if (cm_clan_size($pdo, $c['id']) >= CM_CLAN_MAX) { return 'clan_full_short'; }
    if (!cm_clan_add_member($pdo, $c, $o['to_id'], (int)$ctx['now'])) { return 'clan_busy'; }
    cm_edit($ctx, $o['msg_id'], cm_t('clan_joined', ['name' => cm_user_name($pdo, $o['to_id']), 'clan' => $c['name']]));
    return '';
}

function cm_cmd_clan_kick(array $ctx, array $cmd): void {
    if (!cm_need3($ctx)) { return; }
    $c = cm_clan_owned($ctx);
    if ($c === null) { return; }
    $args = $cmd['args'];
    $t = cm_need_target($ctx, $args);
    if ($t === null) { return; }
    if ((int)$t['id'] === (int)$ctx['actor']) {
        cm_reply($ctx, cm_t('target_self'));
        return;
    }
    $pdo = $ctx['pdo'];
    $theirs = cm_clan_of($pdo, $c['chat_id'], (int)$t['id']);
    if ($theirs === null || $theirs['id'] !== $c['id']) {
        cm_reply($ctx, cm_t('clan_not_member', ['name' => $t['name']]));
        return;
    }
    $pdo->prepare('DELETE FROM cm_clan_members WHERE chat_id = :c AND user_id = :u AND clan_id = :k')
        ->execute([':c' => $c['chat_id'], ':u' => (int)$t['id'], ':k' => $c['id']]);
    cm_reply($ctx, cm_t('clan_kicked', ['name' => $t['name'], 'clan' => $c['name']]), false);
}

// «клан открыт» / «клан закрыт».
function cm_cmd_clan_open(array $ctx, array $cmd): void {
    if (!cm_need3($ctx)) { return; }
    $c = cm_clan_owned($ctx);
    if ($c === null) { return; }
    $open = $cmd['phrase'] === 'клан открыт' ? 1 : 0;
    $ctx['pdo']->prepare('UPDATE cm_clans SET is_open = :o WHERE id = :k')->execute([':o' => $open, ':k' => $c['id']]);
    cm_reply($ctx, cm_t($open ? 'clan_opened' : 'clan_shut', ['name' => $c['name']]), false);
}

// «клан описание текст» — одной строкой, до 200 символов, без ссылок и мата.
function cm_cmd_clan_about(array $ctx, array $cmd): void {
    if (!cm_need3($ctx)) { return; }
    $c = cm_clan_owned($ctx);
    if ($c === null) { return; }
    $text = trim((string)preg_replace('~\s+~u', ' ', $cmd['args'] . ' ' . $cmd['body']));
    if ($text === '' || mb_strlen($text) > CM_CLAN_ABOUT_MAX) {
        cm_reply($ctx, cm_t('clan_about_usage'));
        return;
    }
    if (!cm_clan_text_ok($text)) {
        cm_reply($ctx, cm_t('clan_text_bad'));
        return;
    }
    $ctx['pdo']->prepare('UPDATE cm_clans SET about = :a WHERE id = :k')->execute([':a' => $text, ':k' => $c['id']]);
    cm_reply($ctx, cm_t('clan_about_done'));
}

// «созвать клан» (глава): одно сообщение со скрытыми упоминаниями
// (text_mention, смещения в UTF-16) до 30 участников, кто ещё в чате. Не чаще
// раза в 10 минут: called_at ставится условием, второй созыв внахлёст не пройдёт.
function cm_cmd_clan_call(array $ctx, array $cmd): void {
    if (!cm_need3($ctx)) { return; }
    $c = cm_clan_owned($ctx);
    if ($c === null) { return; }
    $pdo = $ctx['pdo'];
    $now = (int)$ctx['now'];
    $st = $pdo->prepare('SELECT m.user_id FROM cm_clan_members m
        LEFT JOIN cm_members mb ON mb.chat_id = m.chat_id AND mb.user_id = m.user_id
        WHERE m.clan_id = :k AND m.user_id <> :o AND (mb.left_at IS NULL OR mb.left_at = 0)
        ORDER BY m.joined_at, m.user_id LIMIT ' . CM_CLAN_CALL_MAX);
    $st->execute([':k' => $c['id'], ':o' => $c['owner_id']]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    if (!$ids) {
        cm_reply($ctx, cm_t('clan_call_empty'));
        return;
    }
    $up = $pdo->prepare('UPDATE cm_clans SET called_at = :n WHERE id = :k AND called_at <= :t');
    $up->execute([':n' => $now, ':k' => $c['id'], ':t' => $now - CM_CLAN_CALL_GAP]);
    if ($up->rowCount() === 0) {
        $left = $c['called_at'] + CM_CLAN_CALL_GAP - $now;
        cm_reply($ctx, cm_t('clan_call_wait', ['min' => max(1, intdiv($left + 59, 60))]));
        return;
    }
    $text = cm_t('clan_call', ['owner' => cm_actor_name($ctx), 'clan' => $c['name']]) . "\n";
    $ents = [];
    foreach ($ids as $i => $uid) {
        if ($i > 0) { $text .= ', '; }
        $name = cm_user_name($pdo, $uid);
        $ents[] = ['type' => 'text_mention', 'offset' => cm_utf16_len($text), 'length' => cm_utf16_len($name),
                   'user' => ['id' => $uid, 'is_bot' => false, 'first_name' => $name]];
        $text .= $name;
    }
    cm_reply($ctx, $text, false, ['entities' => $ents]);
}
```

- [ ] **Step 4: Команды, раздел, тексты, подключение**

Edit `public_html/api/lib/cm/parse.php` — перед строкой

```php
    'помощь'             => ['help', null, 0, true, false],
```

вставить

```php
    '+клан'              => ['clan_add', 'кланы', 0, false, false],
    '-клан'              => ['clan_del', 'кланы', 0, true, false],
    'клан'               => ['clan_show', 'кланы', 0, false, false],
    'кланы'              => ['clan_top', 'кланы', 0, true, false],
    'вступить'           => ['clan_join', 'кланы', 0, false, false],
    'покинуть клан'      => ['clan_leave', 'кланы', 0, true, false],
    'пригласить'         => ['clan_invite', 'кланы', 0, false, true],
    'исключить'          => ['clan_kick', 'кланы', 0, false, true],
    'клан открыт'        => ['clan_open', 'кланы', 0, true, false],
    'клан закрыт'        => ['clan_open', 'кланы', 0, true, false],
    'клан описание'      => ['clan_about', 'кланы', 0, false, false],
    'созвать клан'       => ['clan_call', 'кланы', 0, true, false],
```

Edit `public_html/api/lib/cm/parse.php`, в `CM_BARE_ARGS` — фрагмент

```php
    'marry'       => '~^$~',
];
```

заменить на

```php
    'marry'       => '~^$~',
    'clan_show'   => '~^$~',
    'clan_join'   => '~^[\p{L}\p{N}][\p{L}\p{N} -]{1,40}$~u',
    'clan_invite' => '~^$~',
    'clan_kick'   => '~^$~',
];
```

Edit `public_html/api/lib/cm/ranks.php`: строку `                     'репутация', 'награды', 'анкета', 'игры', 'рп', 'отношения'];` заменить на `                     'репутация', 'награды', 'анкета', 'игры', 'рп', 'отношения', 'кланы'];`.

Edit `public_html/api/lib/cm/texts.php` — после строки

```php
    'marriages_empty'  => 'Браков пока нет.',
```

добавить

```php
    // кланы
    'clan_usage'       => 'Так: «+клан Название» — от 2 до 32 символов: буквы, цифры, пробел, дефис.',
    'clan_join_usage'  => 'Так: «вступить Название».',
    'clan_text_bad'    => 'Без ссылок и мата: это видно всем.',
    'clan_taken'       => 'Клан «{name}» уже есть.',
    'clan_have_me'     => 'Вы уже в клане «{name}».',
    'clan_created'     => '🛡 Клан «{name}» создан. Глава — {owner}.',
    'clan_none_me'     => 'Вы не в клане.',
    'clan_missing'     => 'Клана «{name}» нет.',
    'clan_owner_only'  => 'Это может только глава клана.',
    'clan_deleted'     => '💥 Клан «{name}» распущен.',
    'clan_info'        => "🛡 Клан «{name}»\nГлава: {owner}\nУчастников: {n}/{max} · {state}\nАктивность за неделю: {msgs}",
    'clan_about_line'  => "\nО клане: {about}",
    'clan_state_open'  => 'открыт',
    'clan_state_closed' => 'вход по приглашению',
    'clan_closed'      => 'Клан «{name}» закрыт: нужно приглашение главы.',
    'clan_full'        => 'В клане «{name}» уже {max} участников.',
    'clan_joined'      => '🛡 {name} теперь в клане «{clan}».',
    'clan_left'        => '{name} покидает клан «{clan}».',
    'clan_owner_stays' => 'Глава не может выйти из клана. Распустить его: «-клан».',
    'clan_kicked'      => '{name} больше не в клане «{clan}».',
    'clan_not_member'  => '{name} не в вашем клане.',
    'clan_them_in'     => '{name} уже в клане.',
    'clan_offer'       => '🛡 {to}, {from} зовёт вас в клан «{clan}».',
    'clan_opened'      => 'Клан «{name}» открыт: вступить может любой.',
    'clan_shut'        => 'Клан «{name}» закрыт: вход только по приглашению.',
    'clan_about_usage' => 'Так: «клан описание текст» — до 200 символов.',
    'clan_about_done'  => 'Описание клана сохранено.',
    'clan_call'        => '📣 {owner} созывает клан «{clan}»:',
    'clan_call_wait'   => 'Созывать клан можно раз в 10 минут. Следующий раз — через {min} мин.',
    'clan_call_empty'  => 'В клане пока никого, кроме главы.',
    'clans_head'       => '🛡 Кланы чата (активность за неделю):',
    'clans_line'       => '{n}. {name} — {msgs} · {members} чел.',
    'clans_empty'      => 'Кланов пока нет.',
    'clan_gone'        => 'Не вышло: клана уже нет.',
    'clan_busy'        => 'Не вышло: уже в другом клане.',
    'clan_full_short'  => 'Не вышло: в клане уже 100 участников.',
```

Edit `public_html/api/lib/cm/cm.php`: строку `          'fun', 'offers', 'dice', 'duel', 'love', 'router', 'cron'] as $cm_part) {` заменить на `          'fun', 'offers', 'dice', 'duel', 'love', 'clans', 'router', 'cron'] as $cm_part) {`.

- [ ] **Step 5: Тест проходит, остальное цело**

Run: `/c/xampp/php/php.exe tests/cm_clans_test.php`
Expected: `93 assertions, 0 failures`.

Run:
```bash
for t in tests/cm_*_test.php tests/php74_compat_test.php; do /c/xampp/php/php.exe -d extension=gd "$t" > /dev/null 2>&1 || echo "FAIL $t"; done; echo done
```
Expected: одна строка `done`.

- [ ] **Step 6: Commit**

```bash
git add public_html/api/lib/cm/clans.php tests/cm_clans_test.php public_html/api/lib/cm/parse.php public_html/api/lib/cm/ranks.php public_html/api/lib/cm/texts.php public_html/api/lib/cm/cm.php
git commit -m "feat(cm): кланы — создание, вступление, приглашения, созыв" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git show --stat HEAD
```
Expected: 6 файлов, без `Bin`.

---

### Task 7: Профиль, помощь, сквозные проверки роутера

**Files:**
- Modify: `public_html/api/lib/cm/profile.php` (`cm_profile_lines`, новая `cm_profile_lines3`)
- Modify: `public_html/api/lib/cm/texts.php` (тексты профиля, `help`)
- Test: `tests/cm_profile_test.php`, `tests/cm_router_test.php` (новые тесты)

**Interfaces:**
- Consumes: этап 2: `cm_profile_lines(array $ctx, int $userId, string $name): array`, `cm_section_on(array $chat, string $section): bool`; `cm_ready3` (Task 1), `cm_game_stat`, `cm_score_text` (Task 3), `cm_couple_of`, `cm_marriage_of`, `cm_partner`, `cm_couple_level`, `cm_days_text` (Task 5), `cm_clan_of` (Task 6).
- Produces:
  - `cm_profile_lines3(array $ctx, int $userId): array` — строки «Пара», «Брак», «Клан», «Дуэли»; `cm_profile_lines` вставляет их после «Награды», перед «Варны», если таблицы этапа 3 есть
  - Тексты: `profile_couple`, `profile_marriage`, `profile_clan`, `profile_duels`; строки этапа 3 в `help`

- [ ] **Step 1: Падающие тесты**

Edit `tests/cm_profile_test.php` — перед строкой

```php
run_tests();
```

вставить

```php
// Пара, брак, клан и счёт дуэлей Пети (id 20) — данные этапа 3.
function pf_games(PDO $pdo): void {
    cm_person($pdo, 20, 'petya', 'Петя');
    cm_person($pdo, 21, 'kolya', 'Коля');
    cm_person($pdo, 22, 'olya', 'Оля');
    $c = CM_CHAT;
    $pdo->exec("INSERT INTO cm_couples (chat_id, a_id, b_id, since, xp, xp_day, xp_today, ended_at) VALUES ($c, 21, 20, 0, 117, 0, 0, 0)");
    $pdo->exec("INSERT INTO cm_marriages (chat_id, a_id, b_id, since, ended_at, ended_by) VALUES ($c, 20, 22, " . (CM_NOW - 125 * 86400) . ", 0, 0)");
    $pdo->exec("INSERT INTO cm_clans (chat_id, name, name_key, owner_id, about, is_open, called_at, created_at) VALUES ($c, 'Волки', 'волки', 20, '', 1, 0, 0)");
    $pdo->exec("INSERT INTO cm_clan_members (chat_id, user_id, clan_id, joined_at) VALUES ($c, 20, 1, 0)");
    $pdo->exec("INSERT INTO cm_game_stats (chat_id, user_id, kind, wins, losses, draws) VALUES ($c, 20, 'duel', 5, 2, 0)");
    $pdo->exec("INSERT INTO cm_game_stats (chat_id, user_id, kind, wins, losses, draws) VALUES ($c, 20, 'dice', 9, 9, 9)");
}

test('профиль: пара, брак, клан и дуэли (этап 3) — после наград, перед варнами', function () {
    $pdo = cm_test_pdo();
    $log = [];
    pf_games($pdo);
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), '+награда 5 @petya Лучший трейдер');
    cm_do(cm_ctx_for($pdo, $log, 7), 'профиль @petya');
    assert_eq("👤 Петя\nРанг: 0 — участник\nСообщений: 0 (сегодня 0)\nРепутация: 0\nНаграды: 🥇 Лучший трейдер\n"
        . "Пара: Коля (уровень 3)\nБрак: Оля (125 дней)\nКлан: Волки\nДуэли: 5 побед / 2 поражения\nВарны: 0/3", cm_last_text($log));
});

test('профиль: выключенный раздел этапа 3 прячет свои строки; без таблиц этапа 3 — как в этапе 2', function () {
    $pdo = cm_test_pdo();
    $log = [];
    pf_games($pdo);
    $o = cm_ctx_for($pdo, $log, CM_OWNER);
    foreach (['отношения', 'кланы', 'игры'] as $s) { cm_do($o, 'дк ' . $s . ' 6'); }
    cm_do(cm_ctx_for($pdo, $log, 7), 'профиль @petya');
    assert_eq("👤 Петя\nРанг: 0 — участник\nСообщений: 0 (сегодня 0)\nРепутация: 0\nВарны: 0/3", cm_last_text($log));
    $pdo = cm_test_pdo();
    pf_games($pdo);
    $pdo->exec('DROP TABLE cm_offers');
    cm_do(cm_ctx_for($pdo, $log, 7), 'профиль @petya');
    assert_eq("👤 Петя\nРанг: 0 — участник\nСообщений: 0 (сегодня 0)\nРепутация: 0\nВарны: 0/3", cm_last_text($log));
});

```

Edit `tests/cm_router_test.php` — перед строкой

```php
test('вебхук: правки сообщений и право удалять в группах', function () {
```

вставить

```php
test('«кто» без префикса — обычная речь и фильтры, «!кто» — ответ; помощь знает этап 3', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), 'фильтр мат вкл');
    rt_run($pdo, cm_gmsg(7, 'кто знает, сука, где ключи'), $log);
    assert_eq(1, count(cm_calls($log, 'deleteMessage')), 'не команда — сработал фильтр мата');
    rt_run($pdo, cm_gmsg(7, '!кто дежурит?'), $log);
    assert_eq('🎯 Кто дежурит? Думаю, U7.', cm_last_text($log));
    foreach (['дуэль', 'кубы', 'обнять', 'встречаться', 'вернуть брак', '+клан', 'созвать клан'] as $w) {
        assert_eq(true, strpos(cm_t('help'), $w) !== false, $w);
    }
});

```

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `/c/xampp/php/php.exe tests/cm_profile_test.php`
Expected: FAIL — `ASSERT FAIL` в «профиль: пара, брак, клан и дуэли …» (строк этапа 3 нет), второй новый тест — `ok`.

Run: `/c/xampp/php/php.exe tests/cm_router_test.php`
Expected: FAIL — `ASSERT FAIL дуэль` и т. д. (в помощи нет этапа 3).

- [ ] **Step 3: Строки профиля**

Edit `public_html/api/lib/cm/profile.php` — после строки

```php
    if ($awards) { $lines[] = cm_t('profile_awards', ['list' => cm_awards_brief($awards)]); }
```

добавить

```php
    if (cm_ready3($pdo)) { $lines = array_merge($lines, cm_profile_lines3($ctx, $userId)); }
```

Edit `public_html/api/lib/cm/profile.php` — перед строкой

```php
function cm_profile_send(array $ctx, array $t): void {
```

вставить

```php
// Строки этапа 3: пара, брак, клан, счёт дуэлей. Выключенный раздел («дк
// отношения 6», «дк кланы 6», «дк игры 6») прячет свои строки; пустых нет.
function cm_profile_lines3(array $ctx, int $userId): array {
    $pdo = $ctx['pdo'];
    $chat = $ctx['chat'];
    $chatId = (int)$chat['chat_id'];
    $out = [];
    if (cm_section_on($chat, 'отношения')) {
        $c = cm_couple_of($pdo, $chatId, $userId);
        if ($c !== null) {
            $out[] = cm_t('profile_couple', ['name' => cm_user_name($pdo, cm_partner($c, $userId)), 'lvl' => cm_couple_level($c['xp'])]);
        }
        $m = cm_marriage_of($pdo, $chatId, $userId);
        if ($m !== null) {
            $out[] = cm_t('profile_marriage', ['name' => cm_user_name($pdo, cm_partner($m, $userId)),
                'days' => cm_days_text((int)$ctx['now'] - $m['since'])]);
        }
    }
    if (cm_section_on($chat, 'кланы')) {
        $cl = cm_clan_of($pdo, $chatId, $userId);
        if ($cl !== null) { $out[] = cm_t('profile_clan', ['name' => $cl['name']]); }
    }
    if (cm_section_on($chat, 'игры')) {
        $s = cm_game_stat($pdo, $chatId, $userId, 'duel');
        if ($s['wins'] + $s['losses'] > 0) { $out[] = cm_t('profile_duels', ['score' => cm_score_text($s, false)]); }
    }
    return $out;
}

```

- [ ] **Step 4: Тексты и помощь**

Edit `public_html/api/lib/cm/texts.php` — после строки

```php
    'clan_full_short'  => 'Не вышло: в клане уже 100 участников.',
```

добавить

```php
    // профиль (этап 3)
    'profile_couple'   => 'Пара: {name} (уровень {lvl})',
    'profile_marriage' => 'Брак: {name} ({days})',
    'profile_clan'     => 'Клан: {name}',
    'profile_duels'    => 'Дуэли: {score}',
```

Edit `public_html/api/lib/cm/texts.php`, текст `help` — строку

```php
        . "🏅 Награды: награды, +награда 3 @ник причина, +награда 5 @ник на 30 дней причина, -награда 12",
```

заменить на

```php
        . "🏅 Награды: награды, +награда 3 @ник причина, +награда 5 @ник на 30 дней причина, -награда 12\n"
        . "🎲 Игры: рандом 1 100, монетка, выбери чай или кофе, инфа текст, шар вопрос, !кто текст, дуэль @ник, дуэли, кубы @ник, кубы\n"
        . "⚖️ Исход игр (ранг 3): дуэли исход мут 10м, кубы исход ничего\n"
        . "🤗 РП (ответом или @ником): обнять, поцеловать, погладить, пожать руку, похвалить, поздравить, накормить, напоить, пощекотать, ущипнуть, укусить, пнуть, ударить, убить\n"
        . "💞 Отношения: встречаться @ник, пара, пары, расстаться, брак @ник, брак, браки, развод, вернуть брак\n"
        . "🛡 Кланы: +клан Название, клан, кланы, вступить Название, покинуть клан, пригласить @ник, исключить @ник, клан открыт, клан закрыт, клан описание текст, созвать клан, -клан",
```

- [ ] **Step 5: Тесты проходят, остальное цело**

Run: `/c/xampp/php/php.exe tests/cm_profile_test.php`
Expected: `47 assertions, 0 failures`.

Run: `/c/xampp/php/php.exe tests/cm_router_test.php`
Expected: `77 assertions, 0 failures`.

Run:
```bash
for t in tests/cm_*_test.php tests/php74_compat_test.php tests/telegram_test.php; do /c/xampp/php/php.exe -d extension=gd "$t" > /dev/null 2>&1 || echo "FAIL $t"; done; echo done
```
Expected: одна строка `done`.

- [ ] **Step 6: Commit**

```bash
git add public_html/api/lib/cm/profile.php public_html/api/lib/cm/texts.php tests/cm_profile_test.php tests/cm_router_test.php
git commit -m "feat(cm): пара, брак, клан и дуэли в профиле; помощь этапа 3" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git show --stat HEAD
```
Expected: 4 файла, без `Bin`.

---

### Task 8: Описание, полный прогон, PR

**Files:**
- Modify: `docs/chat-manager.md` (переписать целиком)

- [ ] **Step 1: Описание**

Write `docs/chat-manager.md` (сначала Read: если этапы 1–2 поменяли в нём что-то по сравнению с текстом ниже — разделы до «Этап 3: запуск», «База спамеров», «Как устроено», — перенести это в новый текст):

````markdown
# Чат-менеджер (этапы 1–3: модерация, активность, игры)

Наш бот модерирует любые группы, куда его добавят: ранги, наказания,
фильтры с триггерами, приветствие и правила, репорты, общая база спамеров,
чистка, заметки, таймеры. С этапа 2 — ещё статистика сообщений, анкета и
профиль, репутация и награды. С этапа 3 — случайные команды, РП, дуэли и
кубы, пары и браки, кланы. Только русский язык. Спеки —
`docs/superpowers/specs/2026-11-07-chat-manager-moderation-design.md`,
`docs/superpowers/specs/2026-11-08-chat-manager-activity-design.md` и
`docs/superpowers/specs/2026-11-08-chat-manager-games-design.md`.

Сам, без команды модератора, бот делает две вещи: удаляет ссылки (кроме
maknemy.com и доменов чата) и банит спамеров из общей базы. Всё остальное
включают модераторы командами (`помощь` в группе). Счётчик сообщений идёт
молча и ничего не отправляет. Проигрыш в играх по умолчанию ничего не стоит:
наказание проигравшему включает модератор.

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

## Этап 3: запуск

1. Мерж PR этапа 3 (после PR этапов 1 и 2) → деплой.
2. phpMyAdmin: выполнить `docs/migrations/2026-11-08-chat-manager-games.sql` —
   строго после миграции этапа 2. Пока её нет, команды этапа 3 отвечают
   «Эта часть бота ещё не настроена.» (случайным командам база не нужна —
   они работают сразу), остальной бот работает как раньше.
3. Больше ничего: кнопки идут тем же вебхуком, просроченные приглашения и
   ходы дуэлей закрывает cron этапа 1 (`cm_cron.php -v` печатает ключ `games`).
4. Проверка в тестовой группе со вторым аккаунтом: `монетка`, `обнять`
   ответом, `дуэль @ник` → «Принять» → пара ходов, `кубы @ник`,
   `встречаться @ник`, `брак @ник`, `+клан Тест`, `пригласить @ник`,
   `созвать клан`, `профиль`.

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

## Разделы этапа 3

| Раздел для `дк` | Команды | Ранг |
| --- | --- | --- |
| `игры` | `рандом [A] [B]`, `монетка`, `выбери чай или кофе`, `инфа` / `шанс <текст>`, `шар <вопрос>`, `!кто <текст>`, `дуэль @ник`, `дуэли`, `кубы @ник`, `кубы` | 0 |
| `рп` | `обнять`, `поцеловать`, `погладить`, `ударить`, `укусить`, `пнуть`, `пожать руку`, `похвалить`, `поздравить`, `накормить`, `напоить`, `пощекотать`, `ущипнуть`, `убить` — ответом или `@ником` | 0 |
| `отношения` | `встречаться @ник`, `расстаться`, `пара`, `пары`, `брак @ник`, `брак`, `развод`, `вернуть брак`, `браки` | 0 |
| `кланы` | `+клан Название`, `-клан`, `клан [Название]`, `кланы`, `вступить Название`, `покинуть клан`, `пригласить @ник`, `исключить @ник`, `клан открыт\|закрыт`, `клан описание …`, `созвать клан` | 0, управляет глава клана |
| `триггеры` | `дуэли исход мут 10м`, `кубы исход ничего` (без слов — что сейчас) | 3 |

Правила этапа 3:

- Проигрыш по умолчанию без наказания. `дуэли исход …` / `кубы исход …` —
  действия как у триггеров (варн, мут, кик, бан, до трёх), исполняет бот с
  причиной «проигрыш в дуэли» / «в кубах». Ставка видна в приглашении. Если
  играет модератор (ранг ≥ 1) или админ Telegram — игра без ставки для обоих.
  Ничья в кубах — без исхода.
- `!кто` — только с префиксом: «кто знает…» — обычная речь. Без префикса
  остальные команды этапа 3 — только ровно команда (с целью, где она нужна);
  у `рандом`, `выбери`, `инфа`, `шар`, `вступить` — с аргументами по шаблону
  (`CM_BARE_ARGS`).
- Приглашения (дуэль, кубы, пара, брак, возврат брака, клан) — кнопки
  «Принять» / «Отказаться», жмёт только адресат. Срок — 5 минут (пара, брак,
  возврат брака — 10). Новое приглашение того же вида закрывает старое. На
  «Принять» условия проверяются заново.
- Дуэль: жребий, кто первым; выстрел — 30% + 20% за каждый свой прицел (не
  больше трёх, потолок 90%), промах обнуляет свои прицелы; на ход — 2 минуты,
  иначе проиграл тот, чей ход. Всё — в одном сообщении, бот его правит.
- Кубы: бот бросает 🎲 за обоих, итог сразу в сообщении приглашения.
- У человека в чате — одна пара, один брак, один клан; пара и брак друг от
  друга не зависят. Уровень пары = 1 + опыт / 50; опыт — +1 за РП друг
  другу, не больше 20 в сутки по Москве. `брак @ник`: если человек уже в
  браке — показывает его брак. `вернуть брак` — в течение 3 дней после
  развода, тот же брак с прежней датой.
- Кланы: имя 2–32 символа, уникально в чате без учёта регистра; до 100
  участников; глава не может выйти — только распустить (`-клан`); `созвать
  клан` — скрытые упоминания до 30 участников, раз в 10 минут; рейтинг —
  сообщения участников за 7 дней.

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
- Этап 3: `fun` (случайные команды, РП, `cm_ready3`, `cm_rand`), `offers`
  (приглашения `cm:p:<id>:y|n`, счёт и исход игр, `cm_games_tick`), `dice`,
  `duel` (ходы `cm:d:<id>:s|a|k`), `love`, `clans`. Роутер: кнопки `p` и `d`,
  `CM_PREFIX_ONLY` — первая строка `cm_run_command`. Профиль — строки
  `cm_profile_lines3`.
- `api/cm_cron.php` — раз в минуту: таймеры, автоудаление, чистка `cm_msgs`
  старше 48 часов, просроченные приглашения и ходы дуэлей.
- Ограничения Telegram: список участников боту не отдают — массовые кики
  только по тем, кого бот видел; сообщения старше 48 часов бот удалить не
  может; мут работает только в супергруппе.
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
git commit -m "docs(cm): чат-менеджер, этап 3 — запуск и устройство" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git show --stat HEAD
```

- [ ] **Step 5: Push и PR**

`master` защищён: только ветка и PR, мержит владелец. Remote — `maknemy` (`origin` мёртв). Если `git push` висит ~21 с при живом `curl` — недоступен IP GitHub: `git -c http.sslbackend=schannel -c http.curloptResolve=github.com:443:<живой IP> push …`.

Сначала узнать, что уже смержено:

```bash
gh pr view 222 --repo kannurali/MaknemyTierlist --json state,mergedAt
gh pr list --repo kannurali/MaknemyTierlist --state all --head feat/chat-manager-activity --json number,state,mergedAt
```

- Оба `MERGED` → `git merge maknemy/master` в ветку (конфликт — разобрать по смыслу, не затирая чужие правки master), снова Step 2 и Step 3. PR — в `master`, в нём только этап 3.
- Хоть один `OPEN` → PR всё равно в `master`: в диффе будут и коммиты незамерженных этапов, после их мержа они сами уйдут из диффа. В описании — «мержить после #222 и #<PR этапа 2>».

Write `<скратчпад>/pr-body.md` (вне репозитория; `#<этап 2>` — номер PR этапа 2 из команды выше):

```markdown
Этап 3 из трёх чат-менеджера — игры и отношения: случайные команды, РП, дуэли и кубы, пары и браки, кланы. Основа — этапы 1 (#222) и 2 (#<этап 2>): **мержить после них**.

**Бот сам ничего не делает:** всё по командам, кнопки правят то же сообщение. **Проигрыш в играх по умолчанию ничего не стоит** — наказание включает модератор (`дуэли исход мут 10м`, `кубы исход ничего`); игра с модератором или админом Telegram — без ставки. Любой раздел выключается: `дк игры 6`, `дк рп 6`, `дк отношения 6`, `дк кланы 6`.

**Команды** (префикс `! . /` не обязателен, кроме `!кто`; цель — ответом или @ником):
- игры: `рандом 1 100`, `монетка`, `выбери чай или кофе`, `инфа …`, `шар …`, `!кто …`, `дуэль @ник` (кнопки «Выстрел / Прицелиться / Сбить прицел», 2 минуты на ход), `дуэли`, `кубы @ник`, `кубы`
- РП: `обнять`, `поцеловать`, `пожать руку`, … — 14 действий, форма глагола по полу из анкеты
- отношения: `встречаться @ник`, `пара`, `пары`, `расстаться`, `брак @ник`, `брак`, `браки`, `развод`, `вернуть брак` (3 дня)
- кланы: `+клан Название`, `клан`, `кланы` (активность за неделю), `вступить …`, `пригласить @ник`, `исключить @ник`, `клан открыт|закрыт`, `клан описание …`, `созвать клан`, `-клан`

Устройство и запуск — `docs/chat-manager.md`, спека — `docs/superpowers/specs/2026-11-08-chat-manager-games-design.md`, план — `docs/superpowers/plans/2026-11-08-chat-manager-games.md`.

**После мержа:**
1. phpMyAdmin: `docs/migrations/2026-11-08-chat-manager-games.sql` — после миграции этапа 2. Пока её нет, команды этапа 3 отвечают «ещё не настроено» (случайные работают сразу), остальной бот работает.
2. Тестовая группа со вторым аккаунтом: `монетка`, `обнять` ответом, `дуэль @ник` → «Принять» → ходы, `кубы @ник`, `брак @ник`, `+клан Тест`, `созвать клан`, `профиль`.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
```

```bash
git push -u maknemy feat/chat-manager-games
gh pr create --repo kannurali/MaknemyTierlist --base master --head feat/chat-manager-games --title "Чат-менеджер, этап 3: игры и отношения" --body-file "<скратчпад>/pr-body.md"
```

`<скратчпад>` — папка скратчпада сессии исполнителя (абсолютный путь). Если этапы 1–2 уже смержены, строку «мержить после них» и номера убрать.
