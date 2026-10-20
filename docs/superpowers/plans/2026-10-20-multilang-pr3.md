# Мультиязычность, PR 3 «Контент» — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** новости, розыгрыши и описания предметов тирлиста показываются на языке страницы (de, fr, es, it, pt, pl, tr, uk), с откатом язык → EN → RU.

**Architecture:** переводы лежат в новой таблице `i18n_texts`, по строке на (вид, запись, поле, язык), с хешем русского исходника. Админка после ответа на сохранение переводит в фоне то, чего нет или что устарело. API с `?lang=` отдаёт одноязычный ответ той же формы: текст языка страницы лежит в «своём» слоте (русские поля на ru, английские на прочих), второй слот пуст, поэтому выбор текста на клиенте (`ru || en` / `en || ru`) не меняется. Без `?lang=` ответ прежний — его читают админка и старые закешированные скрипты.

**Tech Stack:** PHP 7.4, MySQL (бой) / SQLite (тесты), LiteSpeed, ванильный JS, Google gtx.

**Спека:** `docs/superpowers/specs/2026-10-19-multilang-design.md`, раздел 5.

## Global Constraints

- Языки перевода с русского: `de, fr, es, it, pt, pl, tr, uk`. EN у новостей и розыгрышей — как сейчас (колонки, перевод при сохранении, ручной EN не трогать). У предметов ручной `descEn`/`termsEn` главнее, пустой EN тоже переводится в фоне в `i18n_texts` (lang `en`).
- Не переводим: чат, объявления, ники, бота, креативы, админку, значки предметов (`tag` — ключ фильтров), подписи тиров, названия призов.
- До миграции таблицы нет: любое чтение и фоновая задача обязаны молча откатываться на EN/RU.
- `public_html/js`, `css`, разметка страниц — без комментариев; PHP-комментарии можно. Изменённым js поднимать `?v=` на всех страницах выше master и открытых PR.
- PHP 7.4: без match, str_contains, именованных аргументов (`tests/php74_compat_test.php`).
- Тесты: `/c/xampp/php/php.exe -d extension=gd tests/<name>_test.php`, `PHP=/c/xampp/php/php.exe node --test tests/<name>_test.mjs`; известное падение — `chat_test.php` (1).
- Рабочее дерево CRLF (autocrlf=true): после скриптовых правок проверять `git diff --stat` на `Bin` и концы строк.

---

### Task 1: Таблица и миграция

**Files:** `docs/migrations/2026-10-20-i18n-texts.sql`, `schema.sql`, `tests/lib.php` (test_db).

```sql
CREATE TABLE IF NOT EXISTS i18n_texts (
  kind       VARCHAR(8)      NOT NULL,   -- news | gw | item
  ref_id     VARCHAR(40)     NOT NULL,   -- id поста / розыгрыша / предмета
  field      VARCHAR(16)     NOT NULL,   -- title, b3, b3.1, p0, c2, c2.btn, desc, terms
  lang       CHAR(2)         NOT NULL,
  src_hash   CHAR(40)        NOT NULL,   -- sha1 русского исходника
  text       MEDIUMTEXT      NOT NULL,   -- строка или JSON спанов (поля b…)
  updated_at BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (kind, ref_id, field, lang)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- [ ] Миграция, зеркало в schema.sql и test_db; коммит.

### Task 2: Перевод на любой язык

**Files:** `public_html/api/lib/translate.php`, `tests/translate_test.php`.

**Produces:** `tr_google_request(string $text, string $tl = 'en'): ?string` (адрес без `tl=en`, язык — параметр); `tr_translate_units(array $units, callable $request, float $deadline): array` — `$units[key] = ['ru' => string|spans, 'spans' => bool]` → `key => string|spans|null`; строки пачкой через `tr_translate_texts()`, спаны через `tr_spans_encode/decode` и запасной путь по спанам, тире через `tr_fix_dashes`.

- [ ] Тесты: адрес запроса несёт `tl=de`; units со строкой и со спанами (жирный) переводятся подделкой; сломанные теги → по спанам; сбой → null.
- [ ] Реализация, прогон `translate_test.php`, коммит.

### Task 3: Библиотека контента

**Files:** `public_html/api/lib/i18n_content.php` (новый), `tests/i18n_content_test.php` (новый).

**Produces:**
- `I18N_CONTENT_LANGS = ['de','fr','es','it','pt','pl','tr','uk']`, `i18n_api_lang($raw): ?string`.
- `i18n_hash($ru): string` — sha1 строки или `tr_spans_key()` спанов.
- Единицы: `i18n_units_news(array $row)`, `i18n_units_gw(array $row)`, `i18n_units_items(array $tierlist)` → `[ref_id => [field => unit]]`; пустые русские тексты не входят; у предмета `unit['manual']['en']` — есть ли ручной EN.
- `i18n_rows(PDO, string $kind, string $lang, ?array $refIds): array` → `[ref][field] = ['hash','text']`, без таблицы — `[]`.
- `i18n_fill(PDO, string $kind, callable $load, array $langs, callable $requestFor, float $deadline): array` — недостающее по хешу; сначала переиспользует перевод с тем же хешем той же записи, остальное — `tr_translate_units`; перед записью перечитывает `$load()` и пишет только то, что всё ещё актуально; удаляет строки полей, которых больше нет. Upsert — DELETE + INSERT (SQLite/MySQL).
- `i18n_forget(PDO, string $kind, string $ref)`.
- Локализация одноязычного ответа: `i18n_news_localize(array $post, string $lang, array $rows)`, `i18n_gw_localize(array $item, string $lang, array $rows)`, `i18n_tierlist_localize(array $tl, string $lang, array $rows): array [$tl, bool $complete]`.

- [ ] Тесты: единицы (блоки p/quote/list/image, легаси-абзацы, условия, предметы); откат язык → EN → RU; устаревший хеш не показывается; RU-ответ без EN-слота; fill переводит только недостающее, переиспользует перевод при сдвиге блоков, не пишет перевод уже изменённого текста, чистит удалённые поля; без таблицы всё молча.
- [ ] Реализация, прогон, коммит.

### Task 4: API и .htaccess

**Files:** `api/news.php`, `api/giveaways.php`, `api/tierlist.php`, `.htaccess`; тесты `news_test.php`, `giveaway_test.php`, `tierlist_test.php`, `dos_guard_test.php`.

- `handle_news(PDO, ?string $lang = null)`; `handle_giveaways(callable, int, bool, ?string $lang = null)`.
- `tierlist_route(PDO, $rawRev, $rawLang = null)` → 302 сохраняет `&lang=`; 200 → `[200, payload, bool $final]`: неполный перевод отдаётся с кешем на минуту, полный — immutable.
- `.htaccess`: giveaways `!^((test=1&)?lang=(ru|en|de|fr|es|it|pt|pl|tr|uk)|test=1)?$`; tierlist `!^((rev=[0-9]{1,18}&)?lang=(…)|rev=[0-9]{1,18})?$`, promo — отдельным правилом как было.

- [ ] Тесты, реализация, прогон, коммит.

### Task 5: Фоновый перевод из админки

**Files:** `api/news_save.php`, `api/news_delete.php`, `api/giveaway_admin.php`, `api/save.php`, `api/lib/i18n_content.php` (`i18n_after_save(callable $job)`, `i18n_job_news/gw/items`).

- После `json_out()`: `tg_finish_response()`, `set_time_limit`, задача в try/catch. Тирлист — после уведомлений о ценах. Удаление поста/розыгрыша стирает его строки.
- [ ] Тест задач на подделке переводчика; коммит.

### Task 6: Клиент

**Files:** `js/app.js`, `js/news-page.js`, `js/giveaway.js`, страницы с их `?v=`.

- app.js: `?lang=` к `tierlist.php` вне админки; ключ localStorage на публичных страницах свой на язык (`nexus-tierlist-v1-<lang>`), иначе одноязычные данные с публичной страницы попали бы в несохранённую работу админки и ушли бы публикацией.
- news-page.js: `?lang=` вне админки. giveaway.js: `lang=` в адресе (с `test=1&` впереди).
- Время розыгрышей: на русском — по Москве с «МСК», на остальных — местное время посетителя с его поясом (`timeZoneName: "short"`).
- [ ] Тесты (static_cache, node), коммит.

### Task 7: Превью и backfill

**Files:** `public_html/news.php`, `tools/i18n_backfill.php`, `tests/news_page_test.php`, `tests/og_test.php`.

- og:title/description поста — из локализованной строки (`i18n_news_localize`); тирлист — проверить тестом, что заголовок на языке страницы.
- `php tools/i18n_backfill.php [--config=PATH] [--kind=news|gw|item] [--lang=de]` — всё опубликованное, пауза между запросами, итог по видам и языкам; без `--config` ищет `config.php` репозитория, потом `$HOME/config.php`.
- [ ] Тесты, реализация, коммит.

### Task 8: Проверка и PR

- [ ] Весь прогон тестов; ручная проверка /de/news, /de/tierlist, /de/giveaways на локальном стенде с подделанными API.
- [ ] PR: миграция, `php tools/i18n_backfill.php --config=/home/maknemyt/config.php` после мержа, проверки на бою.
