# Мультиязычность, PR 1 «Основа» — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** сервер отдаёт каждую публичную страницу сразу на языке адреса (`/tierlist` — русский, `/en/tierlist` — английский), первый заход сам попадает на язык браузера, а словари интерфейса живут в `public_html/lang/*.json`, общих для PHP и JS.

**Architecture:** язык запроса определяется по приставке в `REQUEST_URI` (`api/lib/i18n.php`); `.htaccess` снимает приставку внутренним рероутом и до кеша LiteSpeed делает 302 по куке `nx_lang` или `Accept-Language`. Шаблоны выводят тексты через `te()`/`ta()`, ссылки — через `u()`; русский вывод остаётся байт в байт прежним, что проверяется снимками страниц. JS получает словарь своего языка отдельным файлом `js/i18n/<язык>.js`, собранным из JSON.

**Tech Stack:** PHP 7.4 (бой) / 8.0 (локально, XAMPP), Apache mod_rewrite / LiteSpeed, ванильный JS, node:test, Playwright 1.62.1 с установленным Chrome.

**Спека:** `docs/superpowers/specs/2026-10-19-multilang-design.md`.

## Global Constraints

- Языки PR 1: `I18N_LANGS = ['ru', 'en']`, `I18N_DEFAULT = 'ru'`. Русский — без приставки, английский — `/en/…`.
- Языки СНГ, которые остаются на русском: `ru|kk|uz|ky|be|az|hy|ka|tg|tk`. Любой другой язык браузера → `en`.
- Кука выбора: `nx_lang=<код>; Path=/; Max-Age=31536000; SameSite=Lax`, плюс `; Secure` только при `https:`.
- Разделы с приставкой: главная, `tierlist`, `calculator`, `profile`, `chat`, `trading`, `trading/new`, `stock`, `support`, `giveaways`, `ads`, `news`, `news/<число>`. `/privacy` и `/terms` без приставки, `/en/privacy` → 301 `/privacy`.
- Русская отрисовка каждой публичной страницы совпадает с master байт в байт, кроме добавленного намеренно: тег словаря (задача 3), `<base href="/" />` у `index.php`, строки hreflang/`og:locale:alternate` (задача 5).
- `public_html`: PHP 7.4 — без `match`, `str_contains`, `str_starts_with`, именованных аргументов, nullsafe (`tests/php74_compat_test.php`).
- `public_html/js`, `public_html/css` и HTML-разметка страниц — без поясняющих комментариев (их видит посетитель). PHP-комментарии можно.
- CSP: никаких встроенных `<script>` без `src` (кроме `application/ld+json`) и атрибутов `on*=`.
- Изменил js/css — подними `?v=` на всех страницах в том же коммите, номер выше master и открытых PR.
- Тесты локально: PHP — `/c/xampp/php/php.exe -d extension=gd tests/<имя>_test.php`; node — `PHP=/c/xampp/php/php.exe node --test tests/<имя>_test.mjs`. Известные локальные падения, не регрессии: `chat_page_test.php` (3), `chat_test.php` (1).
- Скрипты пишутся через Write, не через heredoc в bash (ломает экранирование); после правок `git diff --stat` не должен показывать `Bin`.
- Каждый коммит заканчивается строкой `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Рабочее дерево: `WT=C:/Users/nural/AppData/Local/Temp/claude/C--Users-nural-Desktop-Nexus-Tier-List/b0a93a63-338e-43d3-9234-15c0c829db11/scratchpad/wt`, ветка `feat/i18n-multilang`. Все команды — из `$WT`. В master не пушить.

## Карта файлов

| файл | что | задача |
| --- | --- | --- |
| `public_html/api/lib/i18n.php` | язык запроса, `t/te/ta/u`, `i18n_url`, hreflang, тег словаря | 1, 3 |
| `public_html/lang/ru.json`, `en.json` | словари интерфейса (источник) | 1, 5 |
| `public_html/lang/.htaccess` | закрыть словари от прямых запросов | 1 |
| `public_html/api/_bootstrap.php` | подключить i18n.php | 1 |
| `tools/lib_render.php` | отрисовка страницы отдельным процессом PHP | 2 |
| `tools/i18n_snapshot.php` | снимки всех публичных страниц в файлы | 2 |
| `tools/i18n_templates.php` | одноразовый перевод шаблонов на `te/ta/u` (не коммитится) | 2 |
| 12 публичных `*.php` | тексты, ссылки, тег словаря, голова | 2, 3, 5 |
| `tools/i18n_build.php` | сборка `js/i18n/*.js` и `sitemap.xml` из JSON | 3, 5 |
| `public_html/js/i18n/ru.js`, `en.js` | собранные словари | 3 |
| `public_html/js/i18n.js` | API: язык страницы, `t`, `url`, переключение, миграция | 3 |
| 9 файлов `public_html/js/*.js` | ссылки на разделы через `url()` | 4 |
| `tools/i18n_heads_once.php` | одноразовая правка голов (не коммитится) | 5 |
| `public_html/sitemap.xml` | собирается `i18n_build.php` | 5 |
| `public_html/.htaccess` | приставки, автоопределение | 6 |
| `tools/check-i18n-ui.mjs` | проверка в браузере через локальный Apache | 7 |
| `tests/i18n_lib_test.php`, `tests/i18n_pages_test.php`, `tests/i18n_routes_test.php` | новые тесты | 1, 3, 5, 6 |
| `tests/lib.php`, `tests/i18n_test.mjs` и читатели исходников в тестах | словарь из JSON, «сплющивание» `te/ta/u` | 2, 3, 4 |

---

### Task 1: Ядро i18n в PHP и словари в JSON

**Files:**
- Create: `public_html/api/lib/i18n.php`
- Create: `public_html/lang/ru.json`, `public_html/lang/en.json` (выгрузка из текущего `js/i18n.js`)
- Create: `public_html/lang/.htaccess`
- Modify: `public_html/api/_bootstrap.php` (после `require_once __DIR__ . '/lib/ban.php';`)
- Test: `tests/i18n_lib_test.php`

**Interfaces:**
- Produces (PHP, глобальные):
  - `const I18N_DEFAULT = 'ru'; const I18N_LANGS = ['ru', 'en']; const I18N_OG_LOCALES = ['ru' => 'ru_RU', 'en' => 'en_US']; const I18N_ORIGIN = 'https://maknemy.com';`
  - `i18n_lang_from_uri(string $uri): string`, `i18n_lang(): string`
  - `i18n_dict(string $lang): array` — словарь с откатом язык → en → ru
  - `t(string $key, array $vars = [], ?string $lang = null): string`
  - `te(string $key, array $vars = []): string` — для текста (ENT_NOQUOTES)
  - `ta(string $key, array $vars = []): string` — для атрибута (ENT_QUOTES)
  - `u(string $path, ?string $lang = null): string` — путь с приставкой
  - `i18n_url(string $path, ?string $lang = null): string` — абсолютный адрес
  - `i18n_og_locale(?string $lang = null): string`
  - `i18n_alternates(string $path): string` — строки hreflang + `og:locale:alternate`, оканчиваются `\n`

- [ ] **Step 1: Выгрузить словари в JSON**

```bash
node -e "const I=require('./public_html/js/i18n.js');const fs=require('fs');fs.mkdirSync('public_html/lang',{recursive:true});for(const l of I.supported())fs.writeFileSync('public_html/lang/'+l+'.json',JSON.stringify(I.STRINGS[l],null,4)+'\n');console.log(I.supported(),Object.keys(I.STRINGS.ru).length,Object.keys(I.STRINGS.en).length)"
```

Expected: `[ 'ru', 'en' ] 841 841` (число ключей может отличаться, но одинаковое у обоих).

- [ ] **Step 2: Закрыть словари от прямых запросов**

`public_html/lang/.htaccess`:

```apache
Require all denied
```

- [ ] **Step 3: Написать падающий тест**

`tests/i18n_lib_test.php`:

```php
<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require_once dirname(__DIR__) . '/public_html/api/lib/i18n.php';

$PUB = dirname(__DIR__) . '/public_html';

function with_uri(?string $uri, callable $fn) {
    $had = array_key_exists('REQUEST_URI', $_SERVER);
    $old = $had ? $_SERVER['REQUEST_URI'] : null;
    if ($uri === null) { unset($_SERVER['REQUEST_URI']); } else { $_SERVER['REQUEST_URI'] = $uri; }
    try { return $fn(); }
    finally { if ($had) { $_SERVER['REQUEST_URI'] = $old; } else { unset($_SERVER['REQUEST_URI']); } }
}

test('язык берётся из приставки адреса', function () {
    assert_eq('ru', i18n_lang_from_uri('/'));
    assert_eq('ru', i18n_lang_from_uri('/tierlist'));
    assert_eq('en', i18n_lang_from_uri('/en'));
    assert_eq('en', i18n_lang_from_uri('/en/tierlist?x=1'));
    assert_eq('en', i18n_lang_from_uri('/en/news/12'));
    assert_eq('ru', i18n_lang_from_uri('/ru/tierlist'), 'у русского приставки нет');
    assert_eq('ru', i18n_lang_from_uri('/de/tierlist'), 'неизвестный язык');
    assert_eq('ru', i18n_lang_from_uri('/english'), 'en только целым сегментом');
    assert_eq('ru', i18n_lang_from_uri('/api/news.php'));
    assert_eq('ru', i18n_lang_from_uri('//'), 'мусор в адресе');
});

test('без запроса язык русский, с /en/ — английский', function () {
    assert_eq('ru', with_uri(null, 'i18n_lang'));
    assert_eq('en', with_uri('/en/calculator', 'i18n_lang'));
});

test('t: язык, подстановки, откат на ключ', function () {
    assert_eq('Все', t('filters.all', [], 'ru'));
    assert_eq('All', t('filters.all', [], 'en'));
    assert_eq('nope.key', t('nope.key', [], 'en'));
    assert_eq('All', with_uri('/en/tierlist', function () { return t('filters.all'); }));
    $s = t('msg.confirmDeleteTier', ['tier' => 'S', 'count' => 3], 'en');
    assert_true(strpos($s, 'S') !== false && strpos($s, '3') !== false && strpos($s, '{') === false, $s);
    assert_true(strpos(t('msg.confirmDeleteTier', [], 'ru'), '{tier}') !== false, 'неизвестная подстановка видна');
    assert_eq('constructor', t('constructor', [], 'en'));
});

test('словарь языка дополняется английским и русским', function () {
    assert_eq([], array_values(array_diff(array_keys(i18n_dict('ru')), array_keys(i18n_dict('en')))),
        'в английском словаре есть все русские ключи');
    assert_true(count(i18n_dict('ru')) > 500, 'словарь прочитан');
    assert_eq(i18n_dict('ru'), i18n_dict('xx'), 'неизвестный язык — русский словарь');
});

test('te экранирует только текст, ta — и кавычки', function () {
    $nick = '<b>"x"</b>';
    $te = te('chat.deleteConfirm', ['nick' => $nick]);
    $ta = ta('chat.deleteConfirm', ['nick' => $nick]);
    assert_true(strpos($te, '&lt;b&gt;"x"&lt;/b&gt;') !== false, $te);
    assert_true(strpos($ta, '&lt;b&gt;&quot;x&quot;&lt;/b&gt;') !== false, $ta);
});

test('u и i18n_url добавляют приставку', function () {
    assert_eq('/tierlist', u('/tierlist', 'ru'));
    assert_eq('/en/tierlist', u('/tierlist', 'en'));
    assert_eq('/en', u('/', 'en'));
    assert_eq('/en/ads#pm-card', u('/ads#pm-card', 'en'));
    assert_eq('/en/profile?id=5', u('/profile?id=5', 'en'));
    assert_eq('/en?x=1', u('/?x=1', 'en'));
    assert_eq('/tierlist', u('/tierlist', 'xx'));
    assert_eq('/en/news', with_uri('/en/tierlist', function () { return u('/news'); }));
    assert_eq('https://maknemy.com/', i18n_url('/', 'ru'));
    assert_eq('https://maknemy.com/en', i18n_url('/', 'en'));
    assert_eq('https://maknemy.com/en/news/7', i18n_url('/news/7', 'en'));
});

test('og:locale по языку', function () {
    assert_eq('ru_RU', i18n_og_locale('ru'));
    assert_eq('en_US', i18n_og_locale('en'));
    assert_eq('ru_RU', i18n_og_locale('xx'));
});

test('hreflang: все языки и x-default на русский', function () {
    $h = with_uri('/en/tierlist', function () { return i18n_alternates('/tierlist'); });
    assert_true(strpos($h, '<link rel="alternate" hreflang="ru" href="https://maknemy.com/tierlist" />') !== false, $h);
    assert_true(strpos($h, '<link rel="alternate" hreflang="en" href="https://maknemy.com/en/tierlist" />') !== false, $h);
    assert_true(strpos($h, '<link rel="alternate" hreflang="x-default" href="https://maknemy.com/tierlist" />') !== false, $h);
    assert_true(strpos($h, '<meta property="og:locale:alternate" content="ru_RU" />') !== false, 'русская альтернатива');
    assert_eq(false, strpos($h, 'content="en_US"'), 'себя в альтернативы не пишет');
    assert_eq("\n", substr($h, -1), 'кончается переводом строки: PHP съедает тот, что после ?>');
    $root = with_uri('/', function () { return i18n_alternates('/'); });
    assert_true(strpos($root, 'hreflang="en" href="https://maknemy.com/en" />') !== false, $root);
});

test('словари закрыты от прямых запросов', function () use ($PUB) {
    assert_eq("Require all denied", trim((string)file_get_contents("$PUB/lang/.htaccess")));
});

test('bootstrap подключает i18n', function () use ($PUB) {
    assert_true(strpos((string)file_get_contents("$PUB/api/_bootstrap.php"),
        "require_once __DIR__ . '/lib/i18n.php';") !== false);
});

run_tests();
```

- [ ] **Step 4: Убедиться, что тест падает**

Run: `/c/xampp/php/php.exe -d extension=gd tests/i18n_lib_test.php`
Expected: фатальная ошибка `Failed opening required ... api/lib/i18n.php`.

- [ ] **Step 5: Написать `public_html/api/lib/i18n.php`**

```php
<?php
// Язык страницы и тексты интерфейса.
//
// Язык определяется по приставке адреса: /en/tierlist — английский,
// /tierlist — русский. .htaccess снимает приставку внутренним рероутом, но
// REQUEST_URI остаётся исходным, поэтому PHP видит её и после рероута.
// Тексты лежат в public_html/lang/<язык>.json — те же словари собираются в
// js/i18n/<язык>.js для скриптов (tools/i18n_build.php).
//
// PHP 7.4: без match, str_contains, str_starts_with и именованных аргументов.

const I18N_DEFAULT = 'ru';
const I18N_LANGS = ['ru', 'en'];
const I18N_OG_LOCALES = ['ru' => 'ru_RU', 'en' => 'en_US'];
const I18N_ORIGIN = 'https://maknemy.com';

function i18n_lang_from_uri(string $uri): string {
    $path = parse_url($uri, PHP_URL_PATH);
    if (!is_string($path)) { return I18N_DEFAULT; }
    if (preg_match('~^/([a-z]{2})(?:/|$)~', $path, $m)
        && $m[1] !== I18N_DEFAULT && in_array($m[1], I18N_LANGS, true)) {
        return $m[1];
    }
    return I18N_DEFAULT;
}

function i18n_lang(): string {
    return i18n_lang_from_uri((string)($_SERVER['REQUEST_URI'] ?? '/'));
}

function i18n_read_json(string $path): array {
    $raw = @file_get_contents($path);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($data) ? $data : [];
}

// Словарь языка с откатом: своя строка, иначе английская, иначе русская.
// Склейка через +=, а не array_merge: тот перенумеровал бы ключи-числа.
function i18n_dict(string $lang): array {
    static $cache = [];
    if (!in_array($lang, I18N_LANGS, true)) { $lang = I18N_DEFAULT; }
    if (!isset($cache[$lang])) {
        $out = [];
        foreach (array_unique([$lang, 'en', I18N_DEFAULT]) as $l) {
            $out += i18n_read_json(__DIR__ . '/../../lang/' . $l . '.json');
        }
        $cache[$lang] = $out;
    }
    return $cache[$lang];
}

function t(string $key, array $vars = [], ?string $lang = null): string {
    $dict = i18n_dict($lang === null ? i18n_lang() : $lang);
    $s = array_key_exists($key, $dict) ? (string)$dict[$key] : $key;
    if (!$vars) { return $s; }
    return preg_replace_callback('~\{(\w+)\}~', function ($m) use ($vars) {
        return array_key_exists($m[1], $vars) ? (string)$vars[$m[1]] : $m[0];
    }, $s);
}

// Текст между тегами: кавычки не трогаем, иначе русский вывод перестал бы
// совпадать с прежней разметкой байт в байт.
function te(string $key, array $vars = []): string {
    return htmlspecialchars(t($key, $vars), ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ta(string $key, array $vars = []): string {
    return htmlspecialchars(t($key, $vars), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function u(string $path, ?string $lang = null): string {
    $lang = $lang === null ? i18n_lang() : $lang;
    if ($lang === I18N_DEFAULT || !in_array($lang, I18N_LANGS, true)) { return $path; }
    if ($path === '/') { return '/' . $lang; }
    $second = substr($path, 1, 1);
    if ($second === '?' || $second === '#') { return '/' . $lang . substr($path, 1); }
    return '/' . $lang . $path;
}

function i18n_url(string $path, ?string $lang = null): string {
    return I18N_ORIGIN . u($path, $lang);
}

function i18n_og_locale(?string $lang = null): string {
    $lang = $lang === null ? i18n_lang() : $lang;
    return I18N_OG_LOCALES[$lang] ?? I18N_OG_LOCALES[I18N_DEFAULT];
}

// Строки для <head> индексируемой страницы: адреса всех языковых версий,
// x-default на русскую и og:locale:alternate для остальных языков.
// Оканчивается переводом строки: в шаблоне вызов стоит отдельной строкой, а
// PHP съедает перевод строки сразу после ?>.
function i18n_alternates(string $path): string {
    $out = '';
    foreach (I18N_LANGS as $l) {
        $out .= '<link rel="alternate" hreflang="' . $l . '" href="'
            . htmlspecialchars(i18n_url($path, $l), ENT_QUOTES, 'UTF-8') . '" />' . "\n";
    }
    $out .= '<link rel="alternate" hreflang="x-default" href="'
        . htmlspecialchars(i18n_url($path, I18N_DEFAULT), ENT_QUOTES, 'UTF-8') . '" />' . "\n";
    $self = i18n_lang();
    foreach (I18N_LANGS as $l) {
        if ($l === $self) { continue; }
        $out .= '<meta property="og:locale:alternate" content="' . I18N_OG_LOCALES[$l] . '" />' . "\n";
    }
    return $out;
}
```

- [ ] **Step 6: Подключить в bootstrap**

В `public_html/api/_bootstrap.php` после строки `require_once __DIR__ . '/lib/ban.php';` добавить:

```php
require_once __DIR__ . '/lib/i18n.php';
```

- [ ] **Step 7: Прогнать тест**

Run: `/c/xampp/php/php.exe -d extension=gd tests/i18n_lib_test.php`
Expected: `… assertions, 0 failures`.

Run: `/c/xampp/php/php.exe -d extension=gd tests/php74_compat_test.php`
Expected: `0 failures`.

- [ ] **Step 8: Commit**

```bash
git add public_html/api/lib/i18n.php public_html/lang public_html/api/_bootstrap.php tests/i18n_lib_test.php
git commit -m "feat(i18n): язык по приставке адреса и словари в lang/*.json

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Шаблоны страниц на te/ta/u без изменения русского вывода

**Files:**
- Create: `tools/lib_render.php`, `tools/i18n_snapshot.php` (коммитятся)
- Create: `tools/i18n_templates.php` (одноразовый, НЕ коммитится, удалить в конце задачи)
- Modify: 12 публичных страниц — `home.php index.php calculator.php news.php trading.php trade-new.php stock.php giveaways.php profile.php chat.php support.php promote.php`
- Modify: `tests/lib.php` (функция `i18n_flatten`), читатели исходников в `tests/*_test.php`

**Interfaces:**
- Consumes: `te()`, `ta()`, `u()`, `i18n_dict()` из Task 1.
- Produces:
  - `nx_render_page(string $file, string $uri, array $get = []): string` в `tools/lib_render.php`.
  - `i18n_flatten(string $src): string` в `tests/lib.php` — заменяет в исходнике `<?= te('k') ?>`, `<?= ta('k') ?>`, `<?= u('/p') ?>`, `<?= i18n_url('/p') ?>`, `<?= i18n_lang() ?>`, `<?= i18n_og_locale() ?>`, строку `<?= i18n_alternates(...) ?>` и обёртку JSON-LD на то, что они дают по-русски. Тесты, читающие исходники страниц, продолжают видеть прежнюю разметку.

- [ ] **Step 1: Отрисовщик страниц**

`tools/lib_render.php`:

```php
<?php
// Отрисовка публичной страницы отдельным процессом PHP — так, как её
// исполняет сервер: свой REQUEST_URI, свой $_GET, пустая SQLite в памяти.
// Пользуются tools/i18n_snapshot.php и тесты, которым нужна готовая разметка.
//
// Код для php -r собран только из одинарных кавычек: escapeshellarg на
// Windows заменяет двойные пробелами.

function nx_render_page(string $file, string $uri, array $get = []): string {
    $root = dirname(__DIR__);
    $cfg = sys_get_temp_dir() . '/nx_render_' . getmypid() . '_' . uniqid() . '_config.php';
    file_put_contents($cfg, '<?php return ' . var_export([
        'dsn' => 'sqlite::memory:', 'db_user' => null, 'db_pass' => null, 'images_dir' => '',
        'deploy_secret' => '', 'deploy_repo' => '', 'deploy_path' => '', 'deploy_branch' => '',
    ], true) . ";\n");
    $code = 'define(' . var_export('CONFIG_PATH', true) . ', $argv[2]);'
          . ' $_SERVER[' . var_export('REQUEST_URI', true) . '] = $argv[3];'
          . ' $_SERVER[' . var_export('HTTP_HOST', true) . '] = ' . var_export('maknemy.com', true) . ';'
          . ' $_GET = json_decode(base64_decode($argv[4]), true);'
          . ' require $argv[1];';
    $cmd = escapeshellarg(PHP_BINARY !== '' ? PHP_BINARY : 'php') . ' -r ' . escapeshellarg($code)
         . ' ' . escapeshellarg($root . '/public_html/' . $file)
         . ' ' . escapeshellarg($cfg)
         . ' ' . escapeshellarg($uri)
         . ' ' . escapeshellarg(base64_encode(json_encode((object)$get)))
         . (DIRECTORY_SEPARATOR === '/' ? ' 2>/dev/null' : ' 2>nul');
    $html = shell_exec($cmd);
    @unlink($cfg);
    return is_string($html) ? $html : '';
}
```

- [ ] **Step 2: Снимки страниц**

`tools/i18n_snapshot.php`:

```php
<?php
// Снимки отрисованных публичных страниц — сравнить разметку до и после
// правки шаблонов:
//   php tools/i18n_snapshot.php <папка> [язык]
//   diff -r <папка-до> <папка-после>

require __DIR__ . '/lib_render.php';

const SNAPSHOT_PAGES = [
    'home'       => ['/',                      'home.php',       []],
    'tierlist'   => ['/tierlist',              'index.php',      []],
    'calculator' => ['/calculator',            'calculator.php', []],
    'news'       => ['/news',                  'news.php',       []],
    'news-post'  => ['/news/1',                'news.php',       ['id' => '1']],
    'trading'    => ['/trading',               'trading.php',    []],
    'trade-new'  => ['/trading/new',           'trade-new.php',  []],
    'stock'      => ['/stock',                 'stock.php',      []],
    'giveaways'  => ['/giveaways',             'giveaways.php',  []],
    'profile'    => ['/profile?id=2841062255', 'profile.php',    ['id' => '2841062255']],
    'chat'       => ['/chat',                  'chat.php',       []],
    'support'    => ['/support',               'support.php',    []],
    'ads'        => ['/ads',                   'promote.php',    []],
];

$out  = $argv[1] ?? '';
$lang = $argv[2] ?? 'ru';
if ($out === '') {
    fwrite(STDERR, "usage: php tools/i18n_snapshot.php <out-dir> [lang]\n");
    exit(2);
}
if (!is_dir($out) && !mkdir($out, 0777, true)) {
    fwrite(STDERR, "cannot create $out\n");
    exit(2);
}
foreach (SNAPSHOT_PAGES as $name => $page) {
    [$uri, $file, $get] = $page;
    $req = $lang === 'ru' ? $uri : '/' . $lang . ($uri === '/' ? '' : $uri);
    $html = nx_render_page($file, $req, $get);
    file_put_contents("$out/$name.html", $html);
    printf("%-11s %7d bytes\n", $name, strlen($html));
}
```

- [ ] **Step 3: Снять «до» дважды и убедиться в детерминизме**

```bash
SNAP=C:/Users/nural/AppData/Local/Temp/claude/C--Users-nural-Desktop-Nexus-Tier-List/b0a93a63-338e-43d3-9234-15c0c829db11/scratchpad/snap
/c/xampp/php/php.exe tools/i18n_snapshot.php $SNAP/before
/c/xampp/php/php.exe tools/i18n_snapshot.php $SNAP/before2
diff -r $SNAP/before $SNAP/before2 && echo SAME
```

Expected: 13 строк с размерами (каждая больше 1000 байт) и `SAME`. Если размер страницы меньше 1000 байт — она упала: открыть файл, прочитать ошибку и исправить причину до продолжения. Если `diff` показывает расхождения (время, случайные числа) — записать номера строк, они будут шумом и в шаге 7.

- [ ] **Step 4: Конвертер шаблонов**

`tools/i18n_templates.php` (одноразовый, в git не добавлять):

```php
<?php
// Одноразово: тексты data-i18n, атрибуты по data-i18n-label/-title/
// -placeholder/-alt и ссылки на разделы — на te()/ta()/u(). Меняется только
// то, что по-русски даст те же байты; остальное печатается отчётом.
//   php tools/i18n_templates.php          — только отчёт
//   php tools/i18n_templates.php --write  — переписать файлы
// Обрабатываются только куски HTML вне <?php … ?>.

require __DIR__ . '/../public_html/api/lib/i18n.php';

const TPL_FILES = ['home.php', 'index.php', 'calculator.php', 'news.php', 'trading.php', 'trade-new.php',
                   'stock.php', 'giveaways.php', 'profile.php', 'chat.php', 'support.php', 'promote.php'];
const TPL_ATTRS = ['label' => 'aria-label', 'title' => 'title', 'placeholder' => 'placeholder', 'alt' => 'alt'];
const TPL_LINK_RE = '~(\shref=")(/(?:tierlist|calculator|trading/new|trading|stock|news|giveaways|ads|chat|support|profile)?(?:[?#][^"\'<>]*)?)(")~';

function tpl_ru(string $key): ?string {
    $dict = i18n_dict('ru');
    return array_key_exists($key, $dict) ? (string)$dict[$key] : null;
}

function tpl_convert_html(string $html, array &$report): string {
    $html = preg_replace_callback(
        '~(<([a-zA-Z][a-zA-Z0-9-]*)\b[^<>]*?\sdata-i18n="([^"]+)"[^<>]*>)([^<]*)(</\2>)~',
        function ($m) use (&$report) {
            $ru = tpl_ru($m[3]);
            if ($ru === null) { $report[] = "нет в словаре: {$m[3]}"; return $m[0]; }
            if ($m[4] === '') { $report[] = "пустой элемент, заполняет JS: {$m[3]}"; return $m[0]; }
            if (htmlspecialchars($ru, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8') !== $m[4]) {
                $report[] = "текст {$m[3]} расходится: разметка «{$m[4]}», словарь «{$ru}»";
                return $m[0];
            }
            return $m[1] . "<?= te('" . $m[3] . "') ?>" . $m[5];
        },
        $html
    );
    $html = preg_replace_callback(
        '~<[a-zA-Z][^<>]*\sdata-i18n-(?:label|title|placeholder|alt)="[^"]+"[^<>]*>~',
        function ($m) use (&$report) {
            $tag = $m[0];
            preg_match_all('~\sdata-i18n-(label|title|placeholder|alt)="([^"]+)"~', $tag, $pairs, PREG_SET_ORDER);
            foreach ($pairs as $p) {
                $attr = TPL_ATTRS[$p[1]];
                $key  = $p[2];
                $ru   = tpl_ru($key);
                $re   = '~(\s' . preg_quote($attr, '~') . '=")([^"]*)(")~';
                if ($ru === null) { $report[] = "нет в словаре: $key"; continue; }
                if (!preg_match($re, $tag, $a)) { $report[] = "нет атрибута $attr у $key"; continue; }
                if (htmlspecialchars($ru, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') !== $a[2]) {
                    $report[] = "$attr $key расходится: разметка «{$a[2]}», словарь «{$ru}»";
                    continue;
                }
                $tag = preg_replace($re, '${1}' . "<?= ta('" . $key . "') ?>" . '${3}', $tag, 1);
            }
            return $tag;
        },
        $html
    );
    return preg_replace(TPL_LINK_RE, '${1}<?= u(\'${2}\') ?>${3}', $html);
}

function tpl_leftovers(string $src): array {
    $out = [];
    preg_match_all('~\sdata-i18n="([^"]+)"[^<>]*>(?!<\?= te\()~', $src, $m);
    foreach ($m[1] as $k) { $out[] = "текст не переведён: $k"; }
    preg_match_all('~\sdata-i18n-(?:label|title|placeholder|alt)="([^"]+)"~', $src, $m);
    foreach ($m[1] as $k) {
        if (strpos($src, "<?= ta('" . $k . "') ?>") === false) { $out[] = "атрибут не переведён: $k"; }
    }
    return $out;
}

$write = in_array('--write', $argv, true);
$pub = dirname(__DIR__) . '/public_html';
$total = 0;
foreach (TPL_FILES as $f) {
    $src = file_get_contents("$pub/$f");
    $report = [];
    $out = '';
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok) && $tok[0] === T_INLINE_HTML) { $out .= tpl_convert_html($tok[1], $report); }
        else { $out .= is_array($tok) ? $tok[1] : $tok; }
    }
    $report = array_values(array_unique(array_merge($report, tpl_leftovers($out))));
    $count = function (string $s): int {
        return substr_count($s, '<?= te(') + substr_count($s, '<?= ta(') + substr_count($s, '<?= u(');
    };
    $changed = $count($out) - $count($src);
    echo "== $f: замен $changed\n";
    foreach ($report as $r) { echo "   $r\n"; }
    $total += $changed;
    if ($write && $out !== $src) { file_put_contents("$pub/$f", $out); }
}
echo "всего замен: $total" . ($write ? '' : ' (без --write файлы не тронуты)') . "\n";
```

- [ ] **Step 5: Прогон без записи, разбор отчёта**

Run: `/c/xampp/php/php.exe tools/i18n_templates.php`
Expected: по каждому файлу число замен (всего около 700–800) и список того, что не тронуто. Записать отчёт в файл: `… > $SNAP/report.txt`.

- [ ] **Step 6: Записать и разобрать остаток вручную**

Run: `/c/xampp/php/php.exe tools/i18n_templates.php --write`

Каждую строку отчёта разобрать по правилу:
- «пустой элемент, заполняет JS» — оставить как есть.
- «расходится» — после загрузки JS всё равно заменяет текст словарным, то есть посетитель видит словарь. Перевести руками на `<?= te('key') ?>` (или `<?= ta('key') ?>` в атрибуте). Русский вывод здесь изменится — это намеренно; записать список ключей в сообщение коммита.
- «текст не переведён» у элемента со вложенными тегами или PHP внутри — если текст статический, перевести руками; если внутри разметка, которую JS заменяет `textContent` целиком, — перевести руками на `te()`; если внутри PHP-вывод — оставить.
- «атрибут не переведён», «нет атрибута» — то же правило, что для «расходится».
- «нет в словаре» — оставить (JS показывает ключ уже сейчас) и записать в сообщение коммита.

Отдельно в `profile.php` ссылка с PHP внутри (её регулярка не берёт):

```php
href="/chat?to=<?= htmlspecialchars($pfWho, ENT_QUOTES, 'UTF-8') ?>"
```
→
```php
href="<?= u('/chat') ?>?to=<?= htmlspecialchars($pfWho, ENT_QUOTES, 'UTF-8') ?>"
```

- [ ] **Step 7: Снять «после» и сравнить**

```bash
/c/xampp/php/php.exe tools/i18n_snapshot.php $SNAP/after-t2
diff -r $SNAP/before $SNAP/after-t2
```

Expected: расхождений нет, кроме строк, переведённых руками по правилу «расходится» в шаге 6 (каждую сверить глазами), и шумовых строк из шага 3. Любое другое расхождение — ошибка конвертации: найти место и исправить.

- [ ] **Step 8: Синтаксис PHP**

```bash
for f in home index calculator news trading trade-new stock giveaways profile chat support promote; do /c/xampp/php/php.exe -l public_html/$f.php; done
```
Expected: `No syntax errors detected` у всех 12.

- [ ] **Step 9: «Сплющивание» для тестов, читающих исходники**

В конец `tests/lib.php` добавить:

```php
// Тесты читают исходники страниц и ищут в них готовую русскую разметку.
// После перевода шаблонов на te()/ta()/u() в исходнике вызовы вместо текста;
// здесь они заменяются тем, что дают по-русски, — так проверки видят ту же
// разметку, что и раньше. Остальной PHP не трогается.
function i18n_flatten(string $src): string {
    static $ru = null;
    if ($ru === null) {
        $raw = @file_get_contents(dirname(__DIR__) . '/public_html/lang/ru.json');
        $ru = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
    }
    $src = preg_replace_callback("~<\\?= (te|ta)\\('([^']+)'\\) \\?>~", function ($m) use ($ru) {
        $s = array_key_exists($m[2], $ru) ? (string)$ru[$m[2]] : $m[2];
        return htmlspecialchars($s, ($m[1] === 'te' ? ENT_NOQUOTES : ENT_QUOTES) | ENT_SUBSTITUTE, 'UTF-8');
    }, $src);
    $src = preg_replace("~<\\?= u\\('([^']*)'\\) \\?>~", '$1', $src);
    $src = preg_replace("~<\\?= i18n_url\\('([^']*)'\\) \\?>~", 'https://maknemy.com$1', $src);
    $src = str_replace(['<?= i18n_lang() ?>', '<?= i18n_og_locale() ?>'], ['ru', 'ru_RU'], $src);
    $src = preg_replace("~<\\?= i18n_alternates\\([^\\n]*?\\) \\?>\\n?~", '', $src);
    $src = str_replace("<?php if (i18n_lang() === 'ru'): ?>\n<script type=\"application/ld+json\">",
        '<script type="application/ld+json">', $src);
    $src = preg_replace('~(<script type="application/ld\+json">.*?</script>\n)<\?php endif; \?>\n~s', '$1', $src);
    return $src;
}
```

- [ ] **Step 10: Читатели исходников — через `i18n_flatten`**

Скрипт `$SNAP/../wrap-readers.mjs` (вне репозитория, через Write):

```js
import fs from 'node:fs';
import path from 'node:path';

const dir = process.argv[2];
const re = /(function (?:[a-z]+_read|read_file_or_fail)\(string \$path\): string \{[\s\S]*?\n    return )(.+);(\n\})/g;
for (const f of fs.readdirSync(dir)) {
  if (!f.endsWith('_test.php')) continue;
  const p = path.join(dir, f);
  const s = fs.readFileSync(p, 'utf8');
  const out = s.replace(re, (all, head, expr, tail) =>
    expr.startsWith('i18n_flatten(') ? all : `${head}i18n_flatten(${expr});${tail}`);
  if (out !== s) { fs.writeFileSync(p, out); console.log('wrapped', f); }
}
```

Run: `node ../wrap-readers.mjs tests`
Expected: `wrapped` у `calculator_page_test.php chat_page_test.php csp_test.php dos_guard_test.php home_page_test.php legal_pages_test.php load_priority_test.php metrika_test.php profile_page_test.php static_cache_test.php tags_test.php topbar_test.php trading_page_test.php`.

- [ ] **Step 11: Полный прогон тестов**

```bash
for t in tests/*_test.php; do r=$(/c/xampp/php/php.exe -d extension=gd "$t" 2>&1 | tail -1); echo "$t: $r"; done | grep -v ' 0 failures'
for t in tests/*_test.mjs; do PHP=/c/xampp/php/php.exe node --test "$t" >/dev/null 2>&1 || echo "FAIL $t"; done
```
Expected: только известные `chat_page_test.php: … 3 failures` и `chat_test.php: … 1 failures`. Если упал другой тест, который читает страницу через `file_get_contents` напрямую, обернуть это чтение в `i18n_flatten(...)` и прогнать снова.

- [ ] **Step 12: Удалить конвертер и закоммитить**

```bash
rm tools/i18n_templates.php
git add tools/lib_render.php tools/i18n_snapshot.php tests public_html/*.php
git diff --cached --stat | grep -i bin && echo "BIN FOUND" || true
git commit -m "refactor(i18n): тексты и ссылки шаблонов через te/ta/u

Русский вывод всех публичных страниц совпадает с прежним байт в байт
(tools/i18n_snapshot.php до и после). Тесты, читающие исходники страниц,
сплющивают вызовы обратно в русскую разметку (i18n_flatten).

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```
Если в шаге 6 были ручные правки по «расходится», перечислить их ключи в теле коммита перед строкой Co-Authored-By.

---

### Task 3: Словари для JS, новый `i18n.js`, переключение языка

**Files:**
- Create: `tools/i18n_build.php`
- Create (генерируются): `public_html/js/i18n/ru.js`, `public_html/js/i18n/en.js`
- Modify: `public_html/api/lib/i18n.php` (функция `i18n_dict_tag`)
- Rewrite: `public_html/js/i18n.js`
- Modify: 12 публичных страниц и `public_html/admin-giveaways.php` (тег словаря перед `i18n.js`, `?v=82` → `?v=83`)
- Modify: `tests/lib.php` (`i18n_source`), чтения `i18n.js` в тестах, `tests/profile_page_test.php` (6 проверок с выравниванием), `tests/i18n_test.mjs` (переписать), `tests/i18n_lib_test.php` (дописать)

**Interfaces:**
- Consumes: `i18n_dict()`, `I18N_LANGS`, `i18n_lang()` из Task 1.
- Produces:
  - PHP: `i18n_dict_tag(?string $lang = null, bool $absolute = false): string` — `<script src="js/i18n/<язык>.js?v=<10 hex md5>" fetchpriority="high"></script>\n`.
  - JS `window.I18N`: `supported(): string[]`, `pageLang(): string`, `pickLang(): string` (= `pageLang()`, аргументы игнорирует), `t(key, lang?, vars?)`, `url(path, lang?)`, `stripPrefix(path)`, `go(lang)`, `DEFAULT_LANG`. Словари — в `globalThis.I18N_DICTS[lang]`.
  - `tools/i18n_build.php [--check]`.

- [ ] **Step 1: Падающие тесты для PHP-части**

В `tests/i18n_lib_test.php` перед `run_tests();` дописать:

```php
test('тег словаря: язык страницы и номер по содержимому', function () use ($PUB) {
    $ver = substr(md5_file("$PUB/js/i18n/en.js"), 0, 10);
    assert_eq('<script src="js/i18n/en.js?v=' . $ver . '" fetchpriority="high"></script>' . "\n", i18n_dict_tag('en'));
    assert_eq(0, strpos(i18n_dict_tag('ru', true), '<script src="/js/i18n/ru.js?v='), 'абсолютный путь для админки');
    assert_true(strpos(i18n_dict_tag('xx'), 'js/i18n/ru.js') !== false, 'неизвестный язык — русский');
    assert_true(strpos(with_uri('/en/stock', 'i18n_dict_tag'), 'js/i18n/en.js') !== false, 'по умолчанию — язык адреса');
});

test('собранные словари свежие', function () {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/tools/i18n_build.php') . ' --check';
    exec($cmd, $out, $code);
    assert_eq(0, $code, 'php tools/i18n_build.php --check: ' . implode(' ', $out));
});

test('список языков в js/i18n.js совпадает с I18N_LANGS', function () use ($PUB) {
    preg_match('~var LANGS = \[([^\]]*)\];~', (string)file_get_contents("$PUB/js/i18n.js"), $m);
    assert_true(!empty($m), 'var LANGS найден');
    $js = array_map(function ($s) { return trim($s, " \"'"); }, explode(',', $m[1] ?? ''));
    assert_eq(I18N_LANGS, $js);
});

test('каждая страница с i18n.js подключает словарь прямо перед ним', function () use ($PUB) {
    foreach (['home', 'index', 'calculator', 'news', 'trading', 'trade-new', 'stock', 'giveaways',
              'profile', 'chat', 'support', 'promote'] as $p) {
        $s = (string)file_get_contents("$PUB/$p.php");
        assert_true((bool)preg_match('~<\?= i18n_dict_tag\(\) \?>\n\s*<script src="js/i18n\.js\?v=~', $s), "$p.php");
    }
    $adm = (string)file_get_contents("$PUB/admin-giveaways.php");
    assert_true(strpos($adm, '{$i18nDictTag}<script src="/js/i18n.js?v=') !== false, 'admin-giveaways.php');
    assert_true(strpos($adm, "\$i18nDictTag = i18n_dict_tag('ru', true);") !== false, 'admin-giveaways.php: тег собран');
});
```

Run: `/c/xampp/php/php.exe -d extension=gd tests/i18n_lib_test.php`
Expected: падают 4 новых теста (`Call to undefined function i18n_dict_tag`, нет `tools/i18n_build.php`, нет `var LANGS`, нет тега на страницах).

- [ ] **Step 2: `i18n_dict_tag` в `api/lib/i18n.php`**

Дописать в конец файла:

```php
// Тег словаря интерфейса для js/i18n.js. Номер версии — по содержимому
// файла: словарь меняется вместе с lang/*.json, и ручной номер легко забыть,
// а js отдаётся с кешем на год. Перевод строки в конце — вызов стоит в
// шаблоне отдельной строкой, а PHP съедает перевод строки после ?>.
function i18n_dict_tag(?string $lang = null, bool $absolute = false): string {
    $lang = $lang === null ? i18n_lang() : $lang;
    if (!in_array($lang, I18N_LANGS, true)) { $lang = I18N_DEFAULT; }
    $file = __DIR__ . '/../../js/i18n/' . $lang . '.js';
    $ver = is_file($file) ? substr((string)md5_file($file), 0, 10) : '0';
    return '<script src="' . ($absolute ? '/' : '') . 'js/i18n/' . $lang . '.js?v=' . $ver
        . '" fetchpriority="high"></script>' . "\n";
}
```

- [ ] **Step 3: Сборщик `tools/i18n_build.php`**

```php
<?php
// Собирает из public_html/lang/*.json то, что сайт отдаёт статикой:
//   public_html/js/i18n/<язык>.js — словарь страницы для js/i18n.js
//     (с откатом язык → en → ru, как у t() в PHP).
// Запускать после любой правки словарей: php tools/i18n_build.php
// С --check ничего не пишет и выходит с кодом 1, если файлы устарели —
// так его зовёт tests/i18n_lib_test.php.

require __DIR__ . '/../public_html/api/lib/i18n.php';

function build_dict_js(string $lang): string {
    $json = json_encode(i18n_dict($lang), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return '(function(r){(r.I18N_DICTS=r.I18N_DICTS||{})["' . $lang . '"]=' . $json
        . ';})(typeof globalThis!=="undefined"?globalThis:this);' . "\n";
}

function build_targets(string $pub): array {
    $want = [];
    foreach (I18N_LANGS as $lang) { $want["$pub/js/i18n/$lang.js"] = build_dict_js($lang); }
    return $want;
}

$pub = dirname(__DIR__) . '/public_html';
$check = in_array('--check', $argv, true);
$stale = [];
foreach (build_targets($pub) as $path => $content) {
    $have = is_file($path) ? str_replace("\r\n", "\n", (string)file_get_contents($path)) : null;
    if ($have === $content) { continue; }
    $stale[] = substr($path, strlen($pub) + 1);
    if (!$check) {
        if (!is_dir(dirname($path))) { mkdir(dirname($path), 0777, true); }
        file_put_contents($path, $content);
    }
}
foreach ($stale as $p) { echo ($check ? 'устарел: ' : 'записан: ') . $p . "\n"; }
exit($check && $stale ? 1 : 0);
```

Run: `/c/xampp/php/php.exe tools/i18n_build.php`
Expected: `записан: js/i18n/ru.js`, `записан: js/i18n/en.js`.

- [ ] **Step 4: Переписать `public_html/js/i18n.js` целиком**

```js
(function (root) {
  "use strict";

  var LANGS = ["ru", "en"];
  var DEFAULT_LANG = "ru";
  var COOKIE = "nx_lang";
  var LEGACY_KEY = "nexus-lang-v1";

  function dicts() { return root.I18N_DICTS || {}; }

  function supported() { return LANGS.slice(); }

  function pageLang() {
    var doc = root.document;
    var lang = doc && doc.documentElement ? doc.documentElement.getAttribute("lang") : null;
    return LANGS.indexOf(lang) >= 0 ? lang : DEFAULT_LANG;
  }

  function t(key, lang, vars) {
    var all = dicts();
    var table = all[lang] || all[pageLang()] || all[DEFAULT_LANG] || {};
    if (!Object.prototype.hasOwnProperty.call(table, key)) { return key; }
    var s = table[key];
    if (!vars) { return s; }
    return s.replace(/\{(\w+)\}/g, function (whole, name) {
      return Object.prototype.hasOwnProperty.call(vars, name) ? String(vars[name]) : whole;
    });
  }

  function pickLang() { return pageLang(); }

  function url(path, lang) {
    var l = lang || pageLang();
    if (l === DEFAULT_LANG || LANGS.indexOf(l) < 0) { return path; }
    if (path === "/") { return "/" + l; }
    var second = path.charAt(1);
    if (second === "?" || second === "#") { return "/" + l + path.slice(1); }
    return "/" + l + path;
  }

  function stripPrefix(path) {
    var m = /^\/([a-z]{2})(\/.*)?$/.exec(path || "/");
    if (m && m[1] !== DEFAULT_LANG && LANGS.indexOf(m[1]) >= 0) { return m[2] || "/"; }
    return path || "/";
  }

  function onAdmin() {
    var loc = root.location;
    return !!loc && /^\/admin(\/|$)/.test(loc.pathname);
  }

  function chosen() {
    var doc = root.document;
    var m = doc ? /(?:^|;\s*)nx_lang=([a-z]{2})(?:;|$)/.exec(doc.cookie || "") : null;
    return m && LANGS.indexOf(m[1]) >= 0 ? m[1] : null;
  }

  function remember(lang) {
    var secure = root.location && root.location.protocol === "https:" ? "; Secure" : "";
    try { root.document.cookie = COOKIE + "=" + lang + "; Path=/; Max-Age=31536000; SameSite=Lax" + secure; } catch (_) {}
  }

  function go(lang) {
    if (LANGS.indexOf(lang) < 0) { return; }
    remember(lang);
    if (lang === pageLang() || onAdmin()) { return; }
    var loc = root.location;
    loc.assign(url(stripPrefix(loc.pathname), lang) + loc.search + loc.hash);
  }

  function migrate() {
    var legacy = null;
    try { legacy = root.localStorage.getItem(LEGACY_KEY); } catch (_) { return; }
    if (legacy === null) { return; }
    try { root.localStorage.removeItem(LEGACY_KEY); } catch (_) {}
    if (chosen() || LANGS.indexOf(legacy) < 0) { return; }
    go(legacy);
  }

  var api = {
    DEFAULT_LANG: DEFAULT_LANG,
    supported: supported,
    pageLang: pageLang,
    pickLang: pickLang,
    t: t,
    url: url,
    stripPrefix: stripPrefix,
    go: go
  };

  if (root.document && root.document.addEventListener) {
    root.document.addEventListener("click", function (e) {
      var el = e.target && e.target.closest ? e.target.closest("#langSwitch [data-lang]") : null;
      if (!el) { return; }
      e.preventDefault();
      e.stopImmediatePropagation();
      go(el.getAttribute("data-lang"));
    }, true);
    migrate();
  }

  if (typeof module === "object" && module.exports) {
    LANGS.forEach(function (lang) { if (!dicts()[lang]) { require("./i18n/" + lang + ".js"); } });
    module.exports = api;
  }
  root.I18N = api;
})(typeof globalThis !== "undefined" ? globalThis : this);
```

Почему так: страницы по-прежнему зовут `pickLang(stored, navigator.language)` — теперь он отдаёт язык страницы, и весь их код рисует на нём. Обработчик в фазе захвата на `document` перехватывает нажатие на чип раньше обработчиков страниц, поэтому их «живое» переключение не срабатывает: вместо него переход на адрес языка. В node (тесты) `document` нет, а словари подгружаются `require`.

- [ ] **Step 5: Тег словаря на страницах, `?v=83`**

Скрипт `$SNAP/../dict-tag.mjs` (вне репозитория):

```js
import fs from 'node:fs';

const pub = process.argv[2];
const pages = ['home', 'index', 'calculator', 'news', 'trading', 'trade-new', 'stock', 'giveaways',
               'profile', 'chat', 'support', 'promote'];
for (const p of pages) {
  const f = `${pub}/${p}.php`;
  const s = fs.readFileSync(f, 'utf8');
  const out = s.replace(/\n([ \t]*)<script src="js\/i18n\.js\?v=82"/,
    (all, ind) => `\n${ind}<?= i18n_dict_tag() ?>\n${ind}<script src="js/i18n.js?v=83"`);
  if (out === s) { console.error('NOT FOUND', p); process.exitCode = 1; continue; }
  fs.writeFileSync(f, out);
  console.log('ok', p);
}
const adm = `${pub}/admin-giveaways.php`;
let a = fs.readFileSync(adm, 'utf8');
const before = a;
a = a.replace('<script src="/js/i18n.js?v=82"></script>', '{$i18nDictTag}<script src="/js/i18n.js?v=83"></script>');
a = a.replace("$nav = admin_nav('giveaways');\n", "$nav = admin_nav('giveaways');\n$i18nDictTag = i18n_dict_tag('ru', true);\n");
if (a === before || !a.includes('$i18nDictTag = ')) { console.error('NOT FOUND admin-giveaways'); process.exitCode = 1; }
else { fs.writeFileSync(adm, a); console.log('ok admin-giveaways'); }
```

Run: `node ../dict-tag.mjs public_html`
Expected: 13 строк `ok`, ни одной `NOT FOUND`.

Проверка, что старых номеров не осталось: `grep -rn "i18n.js?v=82" public_html` — пусто.

- [ ] **Step 6: Тесты читают словарь из JSON**

В конец `tests/lib.php`:

```php
// Словарь интерфейса раньше лежал прямо в js/i18n.js, и тесты искали ключи в
// его тексте, отделяя русский блок от английского по строке "\n    en: {".
// Теперь словари в lang/*.json; здесь они склеиваются в тот же вид.
function i18n_source(): string {
    $dir = dirname(__DIR__) . '/public_html/lang';
    $out = '';
    foreach (['ru', 'en'] as $lang) {
        $out .= "\n    $lang: {\n" . (string)file_get_contents("$dir/$lang.json") . "\n";
    }
    return $out;
}
```

Скрипт `$SNAP/../repoint.mjs`:

```js
import fs from 'node:fs';
import path from 'node:path';

const dir = process.argv[2];
const re = /[a-z_]+\((?:\$PUB \. '\/js\/i18n\.js'|"\$pub\/(?:js\/)?i18n\.js"|__DIR__ \. '\/\.\.\/public_html\/js\/i18n\.js')\)/g;
for (const f of fs.readdirSync(dir)) {
  if (!f.endsWith('_test.php')) continue;
  const p = path.join(dir, f);
  const s = fs.readFileSync(p, 'utf8');
  const n = (s.match(re) || []).length;
  if (!n) continue;
  fs.writeFileSync(p, s.replace(re, 'i18n_source()'));
  console.log(f, n);
}
```

Run: `node ../repoint.mjs tests`
Expected: `auth_test.php 1`, `ban_test.php 1`, `calculator_page_test.php 4`, `chat_page_test.php 7`, `home_page_test.php 1`, `legal_pages_test.php 1`, `profile_page_test.php 13`, `stock_test.php 1`, `tags_test.php 3`, `topbar_test.php 3`, `trading_page_test.php 2`.

В `tests/profile_page_test.php` шесть проверок искали выравнивающие пробелы из старого `i18n.js`. Заменить строки

```php
    assert_true(strpos($i18n, '"nick.owner":             "Владелец"') !== false, 'Maknemy — Владелец');
    assert_true(strpos($i18n, '"nick.developer":         "Разработчик"') !== false, 'The Fool — Разработчик');
    assert_true(strpos($i18n, '"nick.owner":             "Owner"') !== false, 'по-английски Owner');
    assert_true(strpos($i18n, '"nick.developer":         "Developer"') !== false, 'по-английски Developer');
    assert_true(strpos($i18n, '"nick.designer":          "Дизайнер"') !== false, 'дизайнер — Дизайнер');
    assert_true(strpos($i18n, '"nick.designer":          "Designer"') !== false, 'по-английски Designer');
```
на
```php
    assert_true((bool)preg_match('~"nick\.owner":\s*"Владелец"~u', $i18n), 'Maknemy — Владелец');
    assert_true((bool)preg_match('~"nick\.developer":\s*"Разработчик"~u', $i18n), 'The Fool — Разработчик');
    assert_true((bool)preg_match('~"nick\.owner":\s*"Owner"~', $i18n), 'по-английски Owner');
    assert_true((bool)preg_match('~"nick\.developer":\s*"Developer"~', $i18n), 'по-английски Developer');
    assert_true((bool)preg_match('~"nick\.designer":\s*"Дизайнер"~u', $i18n), 'дизайнер — Дизайнер');
    assert_true((bool)preg_match('~"nick\.designer":\s*"Designer"~', $i18n), 'по-английски Designer');
```

- [ ] **Step 7: Переписать `tests/i18n_test.mjs`**

```js
// Unit tests for the interface dictionary. Run: node --test tests/i18n_test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import fs from 'node:fs';

const require = createRequire(import.meta.url);
const I18N = require('../public_html/js/i18n.js');
const { t, pickLang, supported, url, stripPrefix } = I18N;

const SRC = {};
for (const lang of supported()) {
    SRC[lang] = JSON.parse(fs.readFileSync(new URL(`../public_html/lang/${lang}.json`, import.meta.url), 'utf8'));
}

test('every language exposes exactly the same keys', () => {
    const [first, ...rest] = supported();
    const reference = Object.keys(SRC[first]).sort();
    for (const lang of rest) {
        const keys = Object.keys(SRC[lang]).sort();
        assert.deepEqual(reference.filter(k => !keys.includes(k)), [], `${lang} is missing keys`);
        assert.deepEqual(keys.filter(k => !reference.includes(k)), [], `${lang} has keys ${first} does not`);
    }
});

test('no translation is empty or left as whitespace', () => {
    for (const lang of supported()) {
        for (const [key, value] of Object.entries(SRC[lang])) {
            assert.equal(typeof value, 'string', `${lang}.${key} is not a string`);
            assert.ok(value.trim().length > 0, `${lang}.${key} is empty`);
        }
    }
});

test('the English table carries no Cyrillic left over from copy-paste', () => {
    for (const [key, value] of Object.entries(SRC.en)) {
        assert.ok(!/[А-Яа-яЁё]/.test(value), `en.${key} still contains Russian: ${value}`);
    }
});

test('every language declares the same placeholders for a key', () => {
    const names = s => (String(s).match(/\{(\w+)\}/g) || []).sort().join(',');
    for (const lang of supported()) {
        for (const key of Object.keys(SRC.ru)) {
            assert.equal(names(SRC[lang][key]), names(SRC.ru[key]), `${lang}: placeholders differ for ${key}`);
        }
    }
});

test('built dictionaries carry the sources', () => {
    for (const lang of supported()) {
        for (const [key, value] of Object.entries(SRC[lang])) {
            assert.equal(globalThis.I18N_DICTS[lang][key], value, `js/i18n/${lang}.js is stale at ${key}`);
        }
    }
});

test('t returns the requested language', () => {
    assert.equal(t('filters.all', 'ru'), 'Все');
    assert.equal(t('filters.all', 'en'), 'All');
});

test('t falls back to the page language (Russian outside a browser)', () => {
    assert.equal(t('filters.all', 'de'), SRC.ru['filters.all']);
    assert.equal(t('filters.all', undefined), SRC.ru['filters.all']);
});

test('t returns the key itself when nothing is defined', () => {
    assert.equal(t('nope.not.here', 'en'), 'nope.not.here');
});

test('t is not fooled by inherited Object properties', () => {
    assert.equal(t('constructor', 'en'), 'constructor');
    assert.equal(t('toString', 'ru'), 'toString');
});

test('t substitutes named variables and keeps unknown ones visible', () => {
    const en = t('msg.confirmDeleteTier', 'en', { tier: 'S', count: 3 });
    assert.ok(en.includes('S') && en.includes('3') && !en.includes('{'), en);
    assert.equal(t('msg.confirmDeleteTier', 'ru', {}).includes('{tier}'), true);
    assert.equal(t('filters.all', 'en', { tier: 'X' }), 'All');
});

test('UI strings that used to be hardcoded now live in the dictionary', () => {
    for (const key of ['footer.urlPrompt', 'msg.pngSaveFailed', 'msg.pngFileHint']) {
        for (const lang of supported()) {
            const value = t(key, lang);
            assert.notEqual(value, key, `${lang}.${key} is missing`);
            assert.ok(value.trim().length > 0, `${lang}.${key} is empty`);
        }
    }
});

test('pickLang follows the page, not storage or the browser', () => {
    assert.equal(pickLang('en', 'en-US'), 'ru');
    assert.equal(pickLang(null, 'en-GB'), 'ru');
    assert.equal(pickLang(undefined, undefined), 'ru');
});

test('url adds the language prefix', () => {
    assert.equal(url('/tierlist', 'en'), '/en/tierlist');
    assert.equal(url('/', 'en'), '/en');
    assert.equal(url('/giveaways#g5', 'en'), '/en/giveaways#g5');
    assert.equal(url('/?x=1', 'en'), '/en?x=1');
    assert.equal(url('/profile?id=7', 'en'), '/en/profile?id=7');
    assert.equal(url('/tierlist', 'ru'), '/tierlist');
    assert.equal(url('/tierlist', 'xx'), '/tierlist');
    assert.equal(url('/tierlist'), '/tierlist', 'outside a browser the page is Russian');
});

test('stripPrefix removes only a known prefix', () => {
    assert.equal(stripPrefix('/en/news/5'), '/news/5');
    assert.equal(stripPrefix('/en'), '/');
    assert.equal(stripPrefix('/tierlist'), '/tierlist');
    assert.equal(stripPrefix('/ru/x'), '/ru/x');
    assert.equal(stripPrefix('/de/x'), '/de/x');
    assert.equal(stripPrefix(''), '/');
});
```

- [ ] **Step 8: Прогон**

```bash
/c/xampp/php/php.exe -d extension=gd tests/i18n_lib_test.php
for t in tests/*_test.php; do r=$(/c/xampp/php/php.exe -d extension=gd "$t" 2>&1 | tail -1); echo "$t: $r"; done | grep -v ' 0 failures'
for t in tests/*_test.mjs; do PHP=/c/xampp/php/php.exe node --test "$t" >/dev/null 2>&1 || echo "FAIL $t"; done
```
Expected: `i18n_lib_test.php … 0 failures`; в общем прогоне только известные `chat_page_test.php` (3) и `chat_test.php` (1); node — без `FAIL`. Если `static_cache_test.php` ругается на строку словаря, похожую на путь `*.js`/`*.css` без `?v=` в `js/i18n/*.js`, — переформулировать эту строку в `lang/*.json` (без имени файла в кавычках), пересобрать `php tools/i18n_build.php` и прогнать снова.

- [ ] **Step 9: Снимок — русский вывод изменился только тегом словаря**

```bash
/c/xampp/php/php.exe tools/i18n_snapshot.php $SNAP/after-t3
diff -r $SNAP/after-t2 $SNAP/after-t3
```
Expected: на каждой из 13 страниц ровно две строки разницы — строка `<script src="js/i18n.js?v=82"` стала `?v=83`, и перед ней появился `<script src="js/i18n/ru.js?v=…" fetchpriority="high"></script>`.

- [ ] **Step 10: Commit**

```bash
git add tools/i18n_build.php public_html/js/i18n public_html/js/i18n.js public_html/api/lib/i18n.php public_html/*.php tests
git diff --cached --stat | grep -i bin && echo "BIN FOUND" || true
git commit -m "feat(i18n): словари JS из lang/*.json, язык берётся со страницы

js/i18n.js больше не держит строки: страница подгружает словарь своего
языка (js/i18n/<язык>.js, собирает tools/i18n_build.php). Чипы RU/EN ведут
на адрес выбранного языка и запоминают выбор в куке nx_lang; прежний выбор
из localStorage переносится в куку один раз.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Ссылки на разделы, собираемые в JS

**Files:**
- Modify: `public_html/js/topbar.js`, `chat-page.js`, `profile-page.js`, `profile-trades.js`, `giveaway.js`, `gw-card.js`, `trade-cards.js`, `trade-new.js`, `news-page.js`
- Modify: подключающие их страницы (`?v=`)
- Modify: `tests/chat_page_test.php`, `tests/profile_page_test.php`, `tests/trading_page_test.php`

**Interfaces:**
- Consumes: `I18N.url(path)` из Task 3.

- [ ] **Step 1: Обновить закреплённые в тестах строки (тесты станут красными)**

`tests/chat_page_test.php`, строка с `a.href = PROFILE_PATH + '?id=' + encodeURIComponent(peer.id);` → искать:
```php
"a.href = siteUrl(PROFILE_PATH + '?id=' + encodeURIComponent(peer.id));"
```

`tests/profile_page_test.php`:
- `'mine.href = PROFILE_PATH;'` → `'mine.href = siteUrl(PROFILE_PATH);'`
- `'location.pathname === PROFILE_PATH && !/[?&]id=/.test(location.search)'` → `'location.pathname === siteUrl(PROFILE_PATH) && !/[?&]id=/.test(location.search)'`
- `"auth.switchAccount('/profile');"` → `"auth.switchAccount(siteUrl('/profile'));"`

`tests/trading_page_test.php`:
- `'location.assign("/trading?posted="'` → `'location.assign(I18N.url("/trading?posted="'`

Run: `for t in chat_page profile_page trading_page; do /c/xampp/php/php.exe -d extension=gd tests/${t}_test.php 2>&1 | tail -1; done`
Expected: новые падения в этих трёх файлах.

- [ ] **Step 2: Правки JS**

`topbar.js` — после строки `var PROFILE_PATH = "/profile";` добавить:
```js
  function siteUrl(path) { return window.I18N && window.I18N.url ? window.I18N.url(path) : path; }
```
и заменить:
- `mine.href = PROFILE_PATH;` → `mine.href = siteUrl(PROFILE_PATH);`
- `var onMine = location.pathname === PROFILE_PATH && !/[?&]id=/.test(location.search);` → `var onMine = location.pathname === siteUrl(PROFILE_PATH) && !/[?&]id=/.test(location.search);`
- `var gwLink = head ? head.querySelector(".mk-promote[href='/giveaways']") : null;` → `var gwLink = head ? head.querySelector(".mk-promote[href='" + siteUrl("/giveaways") + "']") : null;`

`chat-page.js` — после `var i18n = window.I18N;` добавить:
```js
  function siteUrl(path) { return i18n && i18n.url ? i18n.url(path) : path; }
```
и `a.href = PROFILE_PATH + '?id=' + encodeURIComponent(peer.id);` → `a.href = siteUrl(PROFILE_PATH + '?id=' + encodeURIComponent(peer.id));`

`profile-page.js` — после `var auth = window.MKAuth;` добавить:
```js
  function siteUrl(path) { return i18n && i18n.url ? i18n.url(path) : path; }
```
и `auth.switchAccount('/profile');` → `auth.switchAccount(siteUrl('/profile'));`

`profile-trades.js`: `loginUrl: () => "/profile"` → `loginUrl: () => I18N.url("/profile")`

`trade-new.js`: `location.assign("/trading?posted=" + encodeURIComponent(d.id));` → `location.assign(I18N.url("/trading?posted=" + encodeURIComponent(d.id)));`

`news-page.js`: `const url = "https://maknemy.com/news/" + post.id;` → `const url = "https://maknemy.com" + I18N.url("/news/" + post.id);`

`giveaway.js` — после `var JOINED_KEY = "nx-gw-joined-v1";` добавить:
```js
  function siteUrl(path) { return root.I18N && root.I18N.url ? root.I18N.url(path) : path; }
```
и заменить:
- `a.href = "/profile?id=" + encodeURIComponent(w.id);` → `a.href = siteUrl("/profile?id=" + encodeURIComponent(w.id));`
- `all.href = "/giveaways#g" + g.id;` → `all.href = siteUrl("/giveaways#g" + g.id);`

`gw-card.js` — перед `function hrefFor(place, g) {` добавить:
```js
  function siteUrl(path) { return root.I18N && root.I18N.url ? root.I18N.url(path) : path; }

```
и `return place === "stock" ? "/giveaways" : "/giveaways#g" + g.id;` → `return siteUrl(place === "stock" ? "/giveaways" : "/giveaways#g" + g.id);`

`trade-cards.js` — после `const API_TIERLIST = "/api/tierlist.php";` добавить:
```js
  const siteUrl = path => (root.I18N && root.I18N.url ? root.I18N.url(path) : path);
```
и заменить:
- в `chatHref`: `return "/chat?to=" + encodeURIComponent(offer.author.id)` → `return siteUrl("/chat?to=" + encodeURIComponent(offer.author.id)`, а строку `+ "&draft=" + encodeURIComponent(draftFor(offer));` → `+ "&draft=" + encodeURIComponent(draftFor(offer)));`
- `return "/trading/new" + (q.length ? "?" + q.join("&") : "");` → `return siteUrl("/trading/new" + (q.length ? "?" + q.join("&") : ""));`
- `nick.href = "/profile?id=" + encodeURIComponent(offer.author.id);` → `nick.href = siteUrl("/profile?id=" + encodeURIComponent(offer.author.id));`

Проверка синтаксиса:
```bash
for f in topbar chat-page profile-page profile-trades giveaway gw-card trade-cards trade-new news-page; do node --check public_html/js/$f.js && echo "ok $f"; done
```
Expected: 9 строк `ok`.

- [ ] **Step 3: Поднять версии**

Сначала выяснить номера выше master и открытых PR:

```bash
git fetch -q maknemy
for f in topbar chat-page profile-page profile-trades giveaway gw-card trade-cards trade-new news-page; do
  m=$(git grep -ohE "js/$f\.js\?v=[0-9]+" maknemy/master -- public_html | sed 's/.*=//' | sort -n | tail -1)
  o=$(for b in $(gh pr list --repo kannurali/MaknemyTierlist --state open --json headRefName -q '.[].headRefName'); do git grep -ohE "js/$f\.js\?v=[0-9]+" "maknemy/$b" -- public_html 2>/dev/null; done | sed 's/.*=//' | sort -n | tail -1)
  echo "$f master=$m open=${o:-—}"
done
```

Для каждого файла взять `max(master, open) + 1` (на 2026-10-20: topbar 31, chat-page 19, profile-page 6, profile-trades 4, giveaway 12, gw-card 2, trade-cards 5, trade-new 3, news-page 32) и прописать скриптом `$SNAP/../bump.mjs`:

```js
import fs from 'node:fs';
import path from 'node:path';

const pub = process.argv[2];
const bumps = JSON.parse(process.argv[3]);
const files = [];
for (const d of ['', 'api/lib']) {
  for (const f of fs.readdirSync(path.join(pub, d))) if (f.endsWith('.php')) files.push(path.join(pub, d, f));
}
for (const f of files) {
  let s = fs.readFileSync(f, 'utf8');
  const before = s;
  for (const [name, v] of Object.entries(bumps)) {
    s = s.replace(new RegExp(`(js/${name.replace(/[-.]/g, '\\$&')}\\.js\\?v=)\\d+`, 'g'), `$1${v}`);
  }
  if (s !== before) { fs.writeFileSync(f, s); console.log('bumped', path.relative(pub, f)); }
}
```

Run (номера подставить из вывода выше):
```bash
node ../bump.mjs public_html '{"topbar":31,"chat-page":19,"profile-page":6,"profile-trades":4,"giveaway":12,"gw-card":2,"trade-cards":5,"trade-new":3,"news-page":32}'
```

- [ ] **Step 4: Прогон**

```bash
for t in tests/*_test.php; do r=$(/c/xampp/php/php.exe -d extension=gd "$t" 2>&1 | tail -1); echo "$t: $r"; done | grep -v ' 0 failures'
for t in tests/*_test.mjs; do PHP=/c/xampp/php/php.exe node --test "$t" >/dev/null 2>&1 || echo "FAIL $t"; done
```
Expected: только известные `chat_page_test.php` (3) и `chat_test.php` (1); node — без `FAIL`.

- [ ] **Step 5: Commit**

```bash
git add public_html tests
git commit -m "feat(i18n): ссылки на разделы из JS ведут на адрес своего языка

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Голова страниц, hreflang, sitemap

**Files:**
- Create: `tools/i18n_heads_once.php` (одноразовый, НЕ коммитится)
- Modify: 12 публичных страниц, `public_html/lang/ru.json`, `public_html/lang/en.json`
- Modify: `tools/i18n_build.php` (сборка `sitemap.xml`), `public_html/sitemap.xml` (генерируется)
- Test: `tests/i18n_pages_test.php`, `tests/i18n_lib_test.php` (sitemap попадает в `--check` автоматически)

**Interfaces:**
- Consumes: `te/ta/i18n_url/i18n_alternates/i18n_og_locale/i18n_lang` (Task 1), `nx_render_page` (Task 2), `i18n_dict_tag` (Task 3).
- Produces: ключи `meta.<страница>.title|desc|ogTitle|ogDesc`, `meta.profile.titleNick`.

- [ ] **Step 1: Падающий тест страниц**

`tests/i18n_pages_test.php`:

```php
<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require_once dirname(__DIR__) . '/public_html/api/lib/i18n.php';
require_once dirname(__DIR__) . '/tools/lib_render.php';

$PUB = dirname(__DIR__) . '/public_html';

// путь => [файл, $_GET, индексируется ли, ключ страницы, есть ли JSON-LD]
const IP_PAGES = [
    '/'                      => ['home.php',       [],                       true,  'home',       true],
    '/tierlist'              => ['index.php',      [],                       true,  'tierlist',   true],
    '/calculator'            => ['calculator.php', [],                       true,  'calculator', true],
    '/news'                  => ['news.php',       [],                       true,  'news',       false],
    '/trading'               => ['trading.php',    [],                       true,  'trading',    true],
    '/stock'                 => ['stock.php',      [],                       true,  'stock',      true],
    '/giveaways'             => ['giveaways.php',  [],                       true,  'giveaways',  false],
    '/ads'                   => ['promote.php',    [],                       true,  'promote',    false],
    '/trading/new'           => ['trade-new.php',  [],                       false, 'tradeNew',   false],
    '/chat'                  => ['chat.php',       [],                       false, 'chat',       true],
    '/support'               => ['support.php',    [],                       false, 'support',    false],
    '/profile?id=2841062255' => ['profile.php',    ['id' => '2841062255'],   false, 'profile',    false],
];

function ip_uri(string $path, string $lang): string {
    return $lang === 'ru' ? $path : '/' . $lang . ($path === '/' ? '' : $path);
}

test('у каждой публичной страницы base href="/"', function () use ($PUB) {
    foreach (IP_PAGES as $path => $p) {
        assert_true(strpos((string)file_get_contents("$PUB/{$p[0]}"), '<base href="/" />') !== false, $p[0]);
    }
});

foreach (I18N_LANGS as $lang) {
    test("страницы на $lang: язык, заголовок, canonical, hreflang, словарь", function () use ($lang) {
        foreach (IP_PAGES as $path => $p) {
            [$file, $get, $indexable, $key, $ld] = $p;
            $html = nx_render_page($file, ip_uri($path, $lang), $get);
            $tag = "$lang $path";
            assert_true(strlen($html) > 1000, "$tag: страница отрисовалась");
            assert_true(strpos($html, '<html lang="' . $lang . '">') !== false, "$tag: html lang");
            assert_true(strpos($html, 'js/i18n/' . $lang . '.js?v=') !== false, "$tag: словарь своего языка");
            $canon = i18n_url(strtok($path, '?') === '/profile' ? '/profile' : $path, $lang);
            if ($file !== 'profile.php') {
                assert_true(strpos($html, '<link rel="canonical" href="' . $canon . '" />') !== false, "$tag: canonical $canon");
            }
            if ($key !== 'profile') {
                assert_true(strpos($html, '<title>' . htmlspecialchars(t("meta.$key.title", [], $lang), ENT_NOQUOTES, 'UTF-8') . '</title>') !== false,
                    "$tag: title");
            }
            assert_eq($indexable, strpos($html, 'hreflang="x-default"') !== false, "$tag: hreflang");
            assert_eq($ld && $lang === 'ru', strpos($html, 'application/ld+json') !== false, "$tag: JSON-LD только по-русски");
        }
    });
}

test('шапка английской страницы ведёт на английские разделы', function () {
    $html = nx_render_page('index.php', '/en/tierlist');
    foreach (['/en/calculator', '/en/news', '/en/trading', '/en/giveaways', '/en/stock', '/en/ads'] as $href) {
        assert_true(strpos($html, 'href="' . $href . '"') !== false, $href);
    }
    assert_eq(false, strpos($html, 'href="/calculator"'), 'русских ссылок на разделы нет');
    assert_true(strpos($html, 'href="/privacy"') !== false, 'правовые страницы — без приставки');
});

test('og:locale и альтернатива', function () {
    $ru = nx_render_page('calculator.php', '/calculator');
    $en = nx_render_page('calculator.php', '/en/calculator');
    assert_true(strpos($ru, '<meta property="og:locale" content="ru_RU" />') !== false, 'ru');
    assert_true(strpos($ru, '<meta property="og:locale:alternate" content="en_US" />') !== false, 'ru → en');
    assert_true(strpos($en, '<meta property="og:locale" content="en_US" />') !== false, 'en');
    assert_true(strpos($en, '<meta property="og:url" content="https://maknemy.com/en/calculator" />') !== false, 'og:url');
});

test('профиль: заголовок с ником и canonical на своём языке', function () {
    $en = nx_render_page('profile.php', '/en/profile?id=2841062255', ['id' => '2841062255']);
    assert_true(strpos($en, 'https://maknemy.com/en/profile') !== false, 'canonical');
    assert_true(stripos($en, 'player profile | Maknemy Tier List</title>') !== false, 'title (с ником или без)');
});

test('sitemap: все языковые версии и hreflang', function () use ($PUB) {
    $map = (string)file_get_contents("$PUB/sitemap.xml");
    assert_true(strpos($map, 'xmlns:xhtml="http://www.w3.org/1999/xhtml"') !== false, 'пространство xhtml');
    foreach (['/', '/tierlist', '/news', '/calculator', '/trading', '/stock', '/giveaways', '/ads'] as $p) {
        foreach (I18N_LANGS as $l) {
            assert_true(strpos($map, '<loc>' . i18n_url($p, $l) . '</loc>') !== false, "$l $p");
        }
        assert_true(strpos($map, '<xhtml:link rel="alternate" hreflang="x-default" href="' . i18n_url($p, 'ru') . '" />') !== false, "x-default $p");
    }
    assert_true(strpos($map, '<loc>https://maknemy.com/privacy</loc>') !== false, '/privacy');
    assert_eq(false, strpos($map, '/en/privacy'), 'правовые без языковых версий');
});

run_tests();
```

Run: `/c/xampp/php/php.exe -d extension=gd tests/i18n_pages_test.php`
Expected: падения (нет `<base>` у `index.php`, `html lang` всегда `ru`, нет `hreflang`, нет ключей `meta.*`).

- [ ] **Step 2: Одноразовый скрипт голов**

`tools/i18n_heads_once.php` (в git не добавлять):

```php
<?php
// Одноразово: <head> публичных страниц — на ключи словаря и i18n_*().
// Каждая строка-образец обязана встретиться ровно один раз; если нет — ничего
// не пишется. Повторный запуск поэтому упадёт, это нормально.

require __DIR__ . '/../public_html/api/lib/i18n.php';

$pub = dirname(__DIR__) . '/public_html';

$keys = [
    'meta.home.title'         => ['Maknemy — тирлист, цены и новости Blox Fruits | Макнеми',
                                  'Maknemy — Blox Fruits tier list, values and news'],
    'meta.home.desc'          => ['Maknemy — Blox Fruits Market & Database: актуальные трейд-ценности, тир-листы, калькулятор обменов, сток, новости и инструменты для игроков.',
                                  'Maknemy — Blox Fruits Market & Database: up-to-date trade values, tier lists, a trade calculator, the stock, news and tools for players.'],
    'meta.home.ogTitle'       => ['Maknemy — тирлист, цены и новости Blox Fruits',
                                  'Maknemy — Blox Fruits tier list, values and news'],
    'meta.home.ogDesc'        => ['Blox Fruits Market & Database: актуальные трейд-ценности, тир-листы, калькулятор обменов, сток, новости и инструменты для игроков.',
                                  'Blox Fruits Market & Database: up-to-date trade values, tier lists, a trade calculator, the stock, news and tools for players.'],
    'meta.tierlist.title'     => ['Maknemy Tier List — трейд-ценности Blox Fruits | Макнеми тирлист',
                                  'Maknemy Tier List — Blox Fruits trade values'],
    'meta.tierlist.desc'      => ['Maknemy Tier List — актуальный тирлист трейд-ценностей Blox Fruits: фрукты, перманенты, геймпассы, скины и мутации. Спрос, тренды роста и падения, обновляется вручную. Макнеми тирлист.',
                                  'Maknemy Tier List — an up-to-date tier list of Blox Fruits trade values: fruits, permanent fruits, gamepasses, skins and mutations. Demand, rising and falling trends, updated by hand.'],
    'meta.calculator.title'   => ['Калькулятор трейдов Blox Fruits — Maknemy | Макнеми калькулятор',
                                  'Blox Fruits trade calculator — Maknemy'],
    'meta.calculator.desc'    => ['Калькулятор трейдов Blox Fruits от Maknemy (Макнеми): соберите обе стороны сделки по ценам нашего тирлиста и узнайте, выгодна ли она.',
                                  'The Blox Fruits trade calculator from Maknemy: build both sides of a trade at our tier list values and see whether it is worth it.'],
    'meta.calculator.ogTitle' => ['Калькулятор трейдов Blox Fruits', 'Blox Fruits trade calculator'],
    'meta.calculator.ogDesc'  => ['Соберите обе стороны сделки по ценам тирлиста Maknemy и узнайте, выгодна ли она.',
                                  'Build both sides of a trade at Maknemy tier list values and see whether it is worth it.'],
    'meta.news.title'         => ['Новости Blox Fruits и обновления тирлиста | Maknemy Tier List',
                                  'Blox Fruits news and tier list updates | Maknemy Tier List'],
    'meta.news.desc'          => ['Новости Blox Fruits, изменения трейд-ценностей в тирлисте Maknemy и анонсы проекта: апдейты, ребалансы, розыгрыши.',
                                  'Blox Fruits news, trade value changes in the Maknemy tier list and project announcements: updates, rebalances, giveaways.'],
    'meta.news.ogTitle'       => ['Новости Blox Fruits и обновления тирлиста', 'Blox Fruits news and tier list updates'],
    'meta.news.ogDesc'        => ['Апдейты игры, изменения трейд-ценностей и анонсы проекта.',
                                  'Game updates, trade value changes and project announcements.'],
    'meta.trading.title'      => ['Трейдинг Blox Fruits — объявления об обмене | Maknemy',
                                  'Blox Fruits trading — trade listings | Maknemy'],
    'meta.trading.desc'       => ['Доска трейдов Blox Fruits от Maknemy: объявления игроков об обмене фруктов, пермов и геймпассов по ценам тирлиста. Найдите предмет и напишите автору в чат.',
                                  'The Maknemy Blox Fruits trading board: player listings for trading fruits, permanent fruits and gamepasses at tier list values. Find an item and message the author in chat.'],
    'meta.trading.ogTitle'    => ['Трейдинг Blox Fruits', 'Blox Fruits trading'],
    'meta.trading.ogDesc'     => ['Объявления игроков об обмене по ценам тирлиста Maknemy.',
                                  'Player trade listings at Maknemy tier list values.'],
    'meta.tradeNew.title'     => ['Новое объявление — трейдинг | Maknemy', 'New listing — trading | Maknemy'],
    'meta.tradeNew.desc'      => ['Соберите объявление об обмене в Blox Fruits: что вы предлагаете и что хотите взамен — по ценам тирлиста Maknemy.',
                                  'Build a Blox Fruits trade listing: what you offer and what you want in return — at Maknemy tier list values.'],
    'meta.stock.title'        => ['Сток Blox Fruits сейчас — обычный и Mirage | Maknemy',
                                  'Blox Fruits stock now — normal and Mirage | Maknemy'],
    'meta.stock.desc'         => ['Текущий сток фруктов Blox Fruits: что продают обычный дилер и дилер на острове Mirage, цены и точное время смены. Уведомления в Telegram о нужных фруктах.',
                                  'The current Blox Fruits stock: what the normal dealer and the Mirage Island dealer sell, prices and the exact restock time. Telegram alerts for the fruits you want.'],
    'meta.stock.ogTitle'      => ['Сток Blox Fruits сейчас', 'Blox Fruits stock now'],
    'meta.stock.ogDesc'       => ['Обычный сток и Mirage, цены и время смены. Уведомления в Telegram о нужных фруктах.',
                                  'Normal and Mirage stock, prices and restock time. Telegram alerts for the fruits you want.'],
    'meta.giveaways.title'    => ['Розыгрыши Blox Fruits — призы от Maknemy', 'Blox Fruits giveaways — prizes from Maknemy'],
    'meta.giveaways.desc'     => ['Розыгрыши фруктов и предметов Blox Fruits на maknemy.com: призы, условия участия, таймер до итогов и победители прошлых розыгрышей.',
                                  'Blox Fruits fruit and item giveaways on maknemy.com: prizes, entry conditions, a countdown to the results and winners of past giveaways.'],
    'meta.giveaways.ogTitle'  => ['Розыгрыши Blox Fruits', 'Blox Fruits giveaways'],
    'meta.giveaways.ogDesc'   => ['Призы Blox Fruits от Maknemy: участвуйте через Roblox и Telegram.',
                                  'Blox Fruits prizes from Maknemy: enter with Roblox and Telegram.'],
    'meta.profile.title'      => ['Профиль игрока | Maknemy Tier List', 'Player profile | Maknemy Tier List'],
    'meta.profile.titleNick'  => ['{nick} — профиль игрока | Maknemy Tier List', '{nick} — player profile | Maknemy Tier List'],
    'meta.profile.desc'       => ['Профиль игрока Maknemy: ник, статистика сделок и репутация.',
                                  'A Maknemy player profile: nickname, trade stats and reputation.'],
    'meta.chat.title'         => ['Чаты | Maknemy Tier List', 'Chats | Maknemy Tier List'],
    'meta.chat.desc'          => ['Личные сообщения игроков Maknemy: переписка по сделкам и отзывы.',
                                  'Private messages between Maknemy players: trade conversations and reviews.'],
    'meta.support.title'      => ['Центр обращений | Maknemy Tier List', 'Support centre | Maknemy Tier List'],
    'meta.support.desc'       => ['Поддержка Maknemy: сообщите о недобросовестном трейдере или проблеме на сайте.',
                                  'Maknemy support: report a dishonest trader or a problem with the site.'],
    'meta.promote.title'      => ['Реклама на maknemy.com и в Telegram | Maknemy', 'Advertising on maknemy.com and in Telegram | Maknemy'],
    'meta.promote.desc'       => ['Реклама среди игроков Blox Fruits на maknemy.com и в Telegram: баннеры, карточки конкурсов, участники конкурса, оплата за переходы. От $2.',
                                  'Advertise to Blox Fruits players on maknemy.com and in Telegram: banners, giveaway cards, giveaway participants, pay per click. From $2.'],
];

$ru = fn_ru($keys);
function fn_ru(array $keys): array { $o = []; foreach ($keys as $k => $v) { $o[$k] = $v[0]; } return $o; }
function src_text(string $s): string { return htmlspecialchars($s, ENT_NOQUOTES, 'UTF-8'); }
function src_attr(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// Обычные страницы: файл => [ключ, путь, индексируется, есть og:title/description]
$simple = [
    'home.php'       => ['home',       '/',            true,  true],
    'index.php'      => ['tierlist',   '/tierlist',    true,  false],
    'calculator.php' => ['calculator', '/calculator',  true,  true],
    'trading.php'    => ['trading',    '/trading',     true,  true],
    'stock.php'      => ['stock',      '/stock',       true,  true],
    'giveaways.php'  => ['giveaways',  '/giveaways',   true,  true],
    'promote.php'    => ['promote',    '/ads',         true,  false],
    'trade-new.php'  => ['tradeNew',   '/trading/new', false, false],
    'chat.php'       => ['chat',       '/chat',        false, false],
    'support.php'    => ['support',    '/support',     false, false],
];

$edits = [];
foreach ($simple as $file => [$page, $path, $indexable, $og]) {
    $abs = I18N_ORIGIN . $path;
    $e = [
        ['<html lang="ru">', '<html lang="<?= i18n_lang() ?>">'],
        ['<title>' . src_text($ru["meta.$page.title"]) . '</title>', "<title><?= te('meta.$page.title') ?></title>"],
        ['<meta name="description" content="' . src_attr($ru["meta.$page.desc"]) . '" />',
         "<meta name=\"description\" content=\"<?= ta('meta.$page.desc') ?>\" />"],
        ['<link rel="canonical" href="' . $abs . '" />',
         "<link rel=\"canonical\" href=\"<?= i18n_url('$path') ?>\" />" . ($indexable ? "\n<?= i18n_alternates('$path') ?>" : '')],
    ];
    $src = (string)file_get_contents("$pub/$file");
    if (strpos($src, '<meta property="og:locale" content="ru_RU" />') !== false) {
        $e[] = ['<meta property="og:locale" content="ru_RU" />', '<meta property="og:locale" content="<?= i18n_og_locale() ?>" />'];
    }
    if (strpos($src, '<meta property="og:url" content="' . $abs . '" />') !== false) {
        $e[] = ['<meta property="og:url" content="' . $abs . '" />', "<meta property=\"og:url\" content=\"<?= i18n_url('$path') ?>\" />"];
    }
    if ($og) {
        $e[] = ['<meta property="og:title" content="' . src_attr($ru["meta.$page.ogTitle"]) . '" />',
                "<meta property=\"og:title\" content=\"<?= ta('meta.$page.ogTitle') ?>\" />"];
        $e[] = ['<meta property="og:description" content="' . src_attr($ru["meta.$page.ogDesc"]) . '" />',
                "<meta property=\"og:description\" content=\"<?= ta('meta.$page.ogDesc') ?>\" />"];
    }
    $edits[$file] = $e;
}

$edits['index.php'][] = ['<meta name="viewport" content="width=device-width, initial-scale=1.0" />',
                         "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\" />\n<base href=\"/\" />"];

$edits['news.php'] = [
    ['<html lang="ru">', '<html lang="<?= i18n_lang() ?>">'],
    ['<title>' . src_text($ru['meta.news.title']) . '</title>', "<title><?= te('meta.news.title') ?></title>"],
    ['<meta name="description" content="' . src_attr($ru['meta.news.desc']) . '" />',
     "<meta name=\"description\" content=\"<?= ta('meta.news.desc') ?>\" />"],
    ["\$canonicalUrl = 'https://maknemy.com/news' . (\$linkedPostId !== null ? '/' . \$linkedPostId : '');",
     "\$canonicalPath = '/news' . (\$linkedPostId !== null ? '/' . \$linkedPostId : '');\n\$canonicalUrl = i18n_url(\$canonicalPath);"],
    ['<link rel="canonical" href="<?= htmlspecialchars($canonicalUrl, ENT_QUOTES, \'UTF-8\') ?>" />',
     "<link rel=\"canonical\" href=\"<?= htmlspecialchars(\$canonicalUrl, ENT_QUOTES, 'UTF-8') ?>\" />\n<?= i18n_alternates(\$canonicalPath) ?>"],
    ['<meta property="og:locale" content="ru_RU" />', '<meta property="og:locale" content="<?= i18n_og_locale() ?>" />'],
    ["'title'       => 'Новости Blox Fruits и обновления тирлиста',", "'title'       => t('meta.news.ogTitle'),"],
    ["'description' => 'Апдейты игры, изменения трейд-ценностей и анонсы проекта.',", "'description' => t('meta.news.ogDesc'),"],
];

$edits['profile.php'] = [
    ['<html lang="ru">', '<html lang="<?= i18n_lang() ?>">'],
    ["    ? \$pfNick . ' — профиль игрока | Maknemy Tier List'\n    : 'Профиль игрока | Maknemy Tier List';",
     "    ? t('meta.profile.titleNick', ['nick' => \$pfNick])\n    : t('meta.profile.title');"],
    ['<meta name="description" content="' . src_attr($ru['meta.profile.desc']) . '" />',
     "<meta name=\"description\" content=\"<?= ta('meta.profile.desc') ?>\" />"],
    ["        ? 'https://maknemy.com/profile'\n", "        ? i18n_url('/profile')\n"],
    ["        : 'https://maknemy.com/profile?id=' . \$pfWho, ENT_QUOTES",
     "        : i18n_url('/profile?id=' . \$pfWho), ENT_QUOTES"],
];

$out = [];
$fail = false;
foreach ($edits as $file => $list) {
    $src = $out[$file] ?? (string)file_get_contents("$pub/$file");
    foreach ($list as [$old, $new]) {
        $n = substr_count($src, $old);
        if ($n !== 1) { fwrite(STDERR, "$file: «" . strtok($old, "\n") . "» встречается $n раз\n"); $fail = true; continue; }
        $src = str_replace($old, $new, $src);
    }
    $out[$file] = $src;
}
foreach (['home.php', 'index.php', 'calculator.php', 'stock.php', 'trading.php', 'chat.php'] as $file) {
    $n = 0;
    $out[$file] = preg_replace('~^<script type="application/ld\+json">\n.*?\n</script>\n~ms',
        "<?php if (i18n_lang() === 'ru'): ?>\n\$0<?php endif; ?>\n", $out[$file], -1, $n);
    if ($n !== 1) { fwrite(STDERR, "$file: JSON-LD найден $n раз\n"); $fail = true; }
}
if ($fail) { exit(1); }

foreach (['ru' => 0, 'en' => 1] as $lang => $i) {
    $path = "$pub/lang/$lang.json";
    $dict = json_decode((string)file_get_contents($path), true);
    foreach ($keys as $k => $v) {
        if (array_key_exists($k, $dict)) { fwrite(STDERR, "ключ $k уже есть в $lang.json\n"); exit(1); }
        $dict[$k] = $v[$i];
    }
    file_put_contents($path, json_encode($dict, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
}
foreach ($out as $file => $src) { file_put_contents("$pub/$file", $src); }
echo "готово: " . count($out) . " файлов, " . count($keys) . " ключей\n";
```

- [ ] **Step 3: Запустить, проверить, удалить**

```bash
/c/xampp/php/php.exe tools/i18n_heads_once.php
git diff --stat
for f in home index calculator news trading trade-new stock giveaways profile chat support promote; do /c/xampp/php/php.exe -l public_html/$f.php; done
rm tools/i18n_heads_once.php
```
Expected: `готово: 12 файлов, 37 ключей`; `git diff --stat` — 12 страниц и 2 словаря, у словарей только добавления (если `json_encode` переформатировал существующие строки — сравнить `git diff public_html/lang` и убедиться, что поменялось только экранирование, смысл тот же); `No syntax errors` у всех.

- [ ] **Step 4: Sitemap в сборщике**

В `tools/i18n_build.php`:

1. После строки `require __DIR__ . '/../public_html/api/lib/i18n.php';` добавить:

```php
// Разделы с языковыми версиями: путь, changefreq, priority.
const SITEMAP_PAGES = [
    ['/',           'daily',   '1.0'],
    ['/tierlist',   'daily',   '0.9'],
    ['/news',       'daily',   '0.8'],
    ['/calculator', 'weekly',  '0.7'],
    ['/trading',    'daily',   '0.7'],
    ['/stock',      'hourly',  '0.7'],
    ['/giveaways',  'daily',   '0.6'],
    ['/ads',        'monthly', '0.4'],
];
// Правовые страницы двуязычные на одном адресе — языковых версий у них нет.
const SITEMAP_SINGLE = [
    ['/privacy', 'yearly', '0.2'],
    ['/terms',   'yearly', '0.2'],
];

function build_sitemap(): string {
    $x = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
       . "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\" xmlns:xhtml=\"http://www.w3.org/1999/xhtml\">\n";
    foreach (SITEMAP_PAGES as [$path, $freq, $prio]) {
        $alts = '';
        foreach (I18N_LANGS as $l) {
            $alts .= '    <xhtml:link rel="alternate" hreflang="' . $l . '" href="' . i18n_url($path, $l) . "\" />\n";
        }
        $alts .= '    <xhtml:link rel="alternate" hreflang="x-default" href="' . i18n_url($path, I18N_DEFAULT) . "\" />\n";
        foreach (I18N_LANGS as $l) {
            $x .= "  <url>\n    <loc>" . i18n_url($path, $l) . "</loc>\n" . $alts
                . "    <changefreq>$freq</changefreq>\n    <priority>$prio</priority>\n  </url>\n";
        }
    }
    foreach (SITEMAP_SINGLE as [$path, $freq, $prio]) {
        $x .= "  <url>\n    <loc>" . i18n_url($path, I18N_DEFAULT) . "</loc>\n"
            . "    <changefreq>$freq</changefreq>\n    <priority>$prio</priority>\n  </url>\n";
    }
    return $x . "</urlset>\n";
}
```

2. В `build_targets()` перед `return $want;` добавить строку:

```php
    $want["$pub/sitemap.xml"] = build_sitemap();
```

3. В шапке-комментарии файла после строки про `js/i18n/<язык>.js` добавить строку `//   public_html/sitemap.xml        — разделы на всех языках с hreflang.`

Run: `/c/xampp/php/php.exe tools/i18n_build.php`
Expected: `записан: js/i18n/ru.js`, `записан: js/i18n/en.js` (новые ключи `meta.*`), `записан: sitemap.xml`.

- [ ] **Step 5: Прогон**

```bash
/c/xampp/php/php.exe -d extension=gd tests/i18n_pages_test.php
for t in tests/*_test.php; do r=$(/c/xampp/php/php.exe -d extension=gd "$t" 2>&1 | tail -1); echo "$t: $r"; done | grep -v ' 0 failures'
for t in tests/*_test.mjs; do PHP=/c/xampp/php/php.exe node --test "$t" >/dev/null 2>&1 || echo "FAIL $t"; done
```
Expected: `i18n_pages_test.php … 0 failures`; в общем прогоне только известные (3 + 1); node без `FAIL`. Если упал тест, сверяющий `sitemap.xml` с прежним форматом (например, ищет комментарий или порядок записей), — привести проверку к содержанию: нужный `<loc>` есть, запрещённого нет.

- [ ] **Step 6: Снимки — что поменялось по-русски и как выглядит по-английски**

```bash
/c/xampp/php/php.exe tools/i18n_snapshot.php $SNAP/after-t5
/c/xampp/php/php.exe tools/i18n_snapshot.php $SNAP/en-t5 en
diff -r $SNAP/after-t3 $SNAP/after-t5
```
Expected в `diff`: у `tierlist.html` добавлена строка `<base href="/" />`; у `home calculator news news-post trading stock giveaways ads` после canonical добавлены 3 строки `hreflang` и 1 строка `og:locale:alternate`. Больше ничего. В `$SNAP/en-t5/*.html` глазами проверить 2–3 страницы: `<html lang="en">`, английский `<title>`, ссылки шапки на `/en/…`, нет JSON-LD.

- [ ] **Step 7: Commit**

```bash
git add public_html tools/i18n_build.php tests/i18n_pages_test.php
git diff --cached --stat | grep -i bin && echo "BIN FOUND" || true
git commit -m "feat(i18n): голова страниц на языке адреса, hreflang и sitemap

title, description, og, canonical и html lang берутся из словаря и языка
адреса; индексируемые страницы перечисляют все языковые версии (hreflang,
x-default — русская). JSON-LD пока только на русских страницах. sitemap.xml
собирает tools/i18n_build.php. У index.php появился <base href=\"/\">: на
/en/tierlist иначе уехали бы относительные пути.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: `.htaccess` — приставки и автоопределение

**Files:**
- Modify: `public_html/.htaccess` (новый блок после `RewriteRule \.php/ - [F,L]` и перед комментарием к `DirectoryIndex home.php`)
- Test: `tests/i18n_routes_test.php`

**Interfaces:**
- Consumes: `I18N_LANGS`, `I18N_DEFAULT` (Task 1).

- [ ] **Step 1: Падающий тест**

`tests/i18n_routes_test.php`:

```php
<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require_once dirname(__DIR__) . '/public_html/api/lib/i18n.php';

$HT = str_replace("\r\n", "\n", (string)file_get_contents(dirname(__DIR__) . '/public_html/.htaccess'));
$EXTRA = implode('|', array_values(array_diff(I18N_LANGS, [I18N_DEFAULT])));
const RT_SECTIONS = 'tierlist|calculator|profile|chat|trading|trading/new|stock|support|giveaways|ads|news|news/[0-9]+';

test('приставки всех языков снимаются рероутом', function () use ($HT, $EXTRA) {
    assert_true(strpos($HT, "RewriteRule ^($EXTRA)$ /home.php [L]") !== false, 'главная');
    assert_true(strpos($HT, "RewriteRule ^($EXTRA)/(" . RT_SECTIONS . ')$ /$2 [L]') !== false, 'разделы');
    assert_true(strpos($HT, "RewriteRule ^($EXTRA)/(privacy|terms)$ /$2 [L,R=301]") !== false, 'правовые — на адрес без приставки');
    assert_true(strpos($HT, "RewriteRule ^($EXTRA)/$ /$1 [L,R=301]") !== false, 'слэш у главной');
    assert_true(strpos($HT, "RewriteRule ^($EXTRA)/(.+)/$ /$1/$2 [L,R=301]") !== false, 'слэш у разделов');
});

test('выбор языка: кука, языки СНГ, остальные — английский', function () use ($HT, $EXTRA) {
    assert_true(strpos($HT, 'RewriteCond %{HTTP_COOKIE} (^|;\s*)nx_lang=(' . implode('|', I18N_LANGS) . ')(;|$)') !== false, 'кука');
    assert_true(strpos($HT, 'RewriteCond %{HTTP:Accept-Language} ^\s*(ru|kk|uz|ky|be|az|hy|ka|tg|tk)([-_,;\s]|$) [NC]') !== false, 'СНГ');
    assert_true(strpos($HT, 'RewriteRule ^ - [E=NX_PICK:en]') !== false, 'остальные — en');
});

test('перенаправление: только внешний GET/HEAD без ботов, 302, на главную и разделы', function () use ($HT, $EXTRA) {
    $guard = "RewriteCond %{ENV:REDIRECT_STATUS} ^$\n"
           . "RewriteCond %{REQUEST_METHOD} ^(GET|HEAD)$\n"
           . "RewriteCond %{HTTP_USER_AGENT} !(bot|crawl|spider|slurp|preview|facebookexternalhit|vkshare|whatsapp|telegram|discord|skype|lighthouse) [NC]\n"
           . "RewriteCond %{ENV:NX_PICK} ^($EXTRA)$\n";
    assert_true(strpos($HT, $guard . 'RewriteRule ^$ /%1 [R=302,L]') !== false, 'главная');
    assert_true(strpos($HT, $guard . 'RewriteRule ^(' . RT_SECTIONS . ')$ /%1/$1 [R=302,L]') !== false, 'разделы');
});

test('блок языков стоит до разделов и до DirectoryIndex', function () use ($HT) {
    $pick = strpos($HT, 'E=NX_PICK');
    assert_true($pick !== false, 'блок на месте');
    assert_true($pick < strpos($HT, 'DirectoryIndex home.php'), 'раньше DirectoryIndex');
    assert_true($pick < strpos($HT, 'RewriteRule ^tierlist/$ /tierlist [L,R=301]'), 'раньше разделов');
    assert_true($pick > strpos($HT, 'RewriteRule \.php/ - [F,L]'), 'после защиты API');
});

run_tests();
```

Run: `/c/xampp/php/php.exe -d extension=gd tests/i18n_routes_test.php`
Expected: все 4 теста падают.

- [ ] **Step 2: Блок в `.htaccess`**

Вставить перед строкой `# "/" отдаёт главную (home.php), а не тирлист: по макету это два разных`:

```apache
# ---------------------------------- Языки ----------------------------------
# Русский живёт на адресах без приставки, остальные языки — под /<код>
# (список совпадает с I18N_LANGS в api/lib/i18n.php, проверяет
# tests/i18n_routes_test.php). Приставку снимает ВНУТРЕННИЙ рероут на
# обычный адрес раздела: дальше срабатывают правила разделов ниже, а PHP
# узнаёт язык по REQUEST_URI, который после рероута остаётся исходным.
# Приставка снимается только у известных разделов — /en/api, /en/admin и
# прочее отдают 404. Правовые страницы двуязычные на одном адресе, поэтому
# /en/privacy уводится на /privacy: этот адрес вписан в карточку
# OAuth-приложения Roblox.
#
# Автоопределение — только на адресах без приставки и только для внешнего
# запроса: у внутреннего рероута /en/x → /x REDIRECT_STATUS уже выставлен, и
# без этого условия он сам попал бы под редирект и зациклился. Выбор в меню
# (кука nx_lang) важнее языка браузера; языки СНГ остаются на русском;
# остальные получают английский. Боты не перенаправляются: Googlebot языка
# не присылает и должен видеть каждую версию на её адресе. 302, а не 301:
# браузер не должен запоминать перенаправление.
#
# Решение принимается здесь, а не в PHP: CacheLookup выше отдаёт страницу из
# кеша LiteSpeed, не запуская PHP, а рероуты .htaccess исполняются раньше.
RewriteCond %{HTTP_COOKIE} (^|;\s*)nx_lang=(ru|en)(;|$)
RewriteRule ^ - [E=NX_PICK:%2]

RewriteCond %{ENV:NX_PICK} ^$
RewriteCond %{HTTP:Accept-Language} ^\s*(ru|kk|uz|ky|be|az|hy|ka|tg|tk)([-_,;\s]|$) [NC]
RewriteRule ^ - [E=NX_PICK:ru]

RewriteCond %{ENV:NX_PICK} ^$
RewriteCond %{HTTP:Accept-Language} ^\s*[a-z]{2,3}([-_,;\s]|$) [NC]
RewriteRule ^ - [E=NX_PICK:en]

RewriteCond %{ENV:REDIRECT_STATUS} ^$
RewriteCond %{REQUEST_METHOD} ^(GET|HEAD)$
RewriteCond %{HTTP_USER_AGENT} !(bot|crawl|spider|slurp|preview|facebookexternalhit|vkshare|whatsapp|telegram|discord|skype|lighthouse) [NC]
RewriteCond %{ENV:NX_PICK} ^(en)$
RewriteRule ^$ /%1 [R=302,L]

RewriteCond %{ENV:REDIRECT_STATUS} ^$
RewriteCond %{REQUEST_METHOD} ^(GET|HEAD)$
RewriteCond %{HTTP_USER_AGENT} !(bot|crawl|spider|slurp|preview|facebookexternalhit|vkshare|whatsapp|telegram|discord|skype|lighthouse) [NC]
RewriteCond %{ENV:NX_PICK} ^(en)$
RewriteRule ^(tierlist|calculator|profile|chat|trading|trading/new|stock|support|giveaways|ads|news|news/[0-9]+)$ /%1/$1 [R=302,L]

RewriteRule ^(en)/$ /$1 [L,R=301]
RewriteRule ^(en)/(.+)/$ /$1/$2 [L,R=301]
RewriteRule ^(en)/(privacy|terms)$ /$2 [L,R=301]
RewriteRule ^(en)$ /home.php [L]
RewriteRule ^(en)/(tierlist|calculator|profile|chat|trading|trading/new|stock|support|giveaways|ads|news|news/[0-9]+)$ /$2 [L]

```

- [ ] **Step 3: Тест зелёный**

Run: `/c/xampp/php/php.exe -d extension=gd tests/i18n_routes_test.php`
Expected: `0 failures`.

- [ ] **Step 4: Проверка на локальном Apache**

Подготовка (файлы вне репозитория, кроме `config.php` в корне `$WT` — он в `.gitignore`; проверить `git status`, что он не виден):

`$WT/config.php`:
```php
<?php return ['dsn' => 'sqlite::memory:', 'db_user' => null, 'db_pass' => null, 'images_dir' => '',
              'deploy_secret' => '', 'deploy_repo' => '', 'deploy_path' => '', 'deploy_branch' => ''];
```

`$SNAP/../httpd-i18n.conf` (подставить реальные пути вместо `<WT>` и `<SCRATCH>`):
```apache
ServerRoot "C:/xampp/apache"
Listen 127.0.0.1:8471
ServerName maknemy.com
LoadModule authz_core_module modules/mod_authz_core.so
LoadModule dir_module modules/mod_dir.so
LoadModule mime_module modules/mod_mime.so
LoadModule rewrite_module modules/mod_rewrite.so
LoadModule headers_module modules/mod_headers.so
LoadModule log_config_module modules/mod_log_config.so
LoadFile "C:/xampp/php/php8ts.dll"
LoadFile "C:/xampp/php/libsqlite3.dll"
LoadModule php_module "C:/xampp/php/php8apache2_4.dll"
PHPIniDir "C:/xampp/php"
<FilesMatch "\.php$">
  SetHandler application/x-httpd-php
</FilesMatch>
TypesConfig conf/mime.types
DocumentRoot "<WT>/public_html"
<Directory "<WT>/public_html">
  AllowOverride All
  Require all granted
</Directory>
ErrorLog "<SCRATCH>/apache-error.log"
PidFile "<SCRATCH>/httpd.pid"
```

Запуск в фоне: `C:/xampp/apache/bin/httpd.exe -X -f <SCRATCH>/httpd-i18n.conf` (через `run_in_background`).

Скрипт проверки `$SNAP/../curl-i18n.sh`:

```bash
#!/usr/bin/env bash
B=http://127.0.0.1:8471
H=(-H "Host: maknemy.com" -H "X-Forwarded-Proto: https")
chk() { # name expected-status expected-location-suffix curl-args...
  local name=$1 want=$2 loc=$3; shift 3
  local out; out=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "${H[@]}" "$@")
  local code=${out%% *} got=${out#* }
  if [ "$code" = "$want" ] && { [ -z "$loc" ] || [[ "$got" == *"$loc" ]]; }; then echo "ok   $name"; else echo "FAIL $name: $out"; fi
}
chk "de-браузер → /en/tierlist"     302 /en/tierlist  -H "Accept-Language: de-DE,de;q=0.9" $B/tierlist
chk "en-браузер на корне → /en"     302 /en           -H "Accept-Language: en-US" $B/
chk "query сохраняется"             302 "/en/calculator?l=1" -H "Accept-Language: fr" "$B/calculator?l=1"
chk "ru-браузер остаётся"           200 ""            -H "Accept-Language: ru-RU,ru;q=0.9,en;q=0.8" $B/tierlist
chk "kk-браузер остаётся"           200 ""            -H "Accept-Language: kk-KZ" $B/tierlist
chk "без Accept-Language остаётся"  200 ""            $B/tierlist
chk "кука ru сильнее браузера"      200 ""            -H "Accept-Language: en" -H "Cookie: a=1; nx_lang=ru" $B/tierlist
chk "кука en сильнее браузера"      302 /en/tierlist  -H "Accept-Language: ru" -H "Cookie: nx_lang=en" $B/tierlist
chk "бот не перенаправляется"       200 ""            -H "Accept-Language: en" -A "Mozilla/5.0 (compatible; Googlebot/2.1)" $B/tierlist
chk "POST не перенаправляется"      200 ""            -X POST -H "Accept-Language: en" $B/tierlist
chk "API не перенаправляется"       200 ""            -H "Accept-Language: en" $B/api/session.php
chk "/en/tierlist отдаётся"         200 ""            -H "Accept-Language: ru" $B/en/tierlist
chk "/en отдаётся"                  200 ""            $B/en
chk "/en/news/1 — это news.php"     404 ""            $B/en/news/1
chk "/en/tierlist/ → без слэша"     301 /en/tierlist  $B/en/tierlist/
chk "/en/ → /en"                    301 /en           $B/en/
chk "/en/privacy → /privacy"        301 /privacy      $B/en/privacy
chk "/en/admin — 404"               404 ""            $B/en/admin
chk "/en/api/state.php — 404"       404 ""            $B/en/api/state.php
for p in /en /en/tierlist /en/calculator /en/news /en/trading /en/trading/new /en/stock /en/giveaways /en/ads /en/chat /en/support "/en/profile?id=2841062255"; do
  curl -s "${H[@]}" "$B$p" | grep -q '<html lang="en">' && echo "ok   lang=en $p" || echo "FAIL lang=en $p"
done
curl -s "${H[@]}" "$B/tierlist" | grep -q '<html lang="ru">' && echo "ok   lang=ru /tierlist" || echo "FAIL lang=ru /tierlist"
```

Run: `bash $SNAP/../curl-i18n.sh`
Expected: все строки `ok`. Строка «/en/news/1» ожидает 404 от `news.php` (поста 1 в пустой базе нет) — проверить, что это страница сайта, а не 404 Apache: `curl -s -H "Host: maknemy.com" -H "X-Forwarded-Proto: https" $B/en/news/1 | grep -c 'lang="en"'` → `1`. «API не перенаправляется» и «POST не перенаправляется» допускают любой код, кроме 302: если `session.php` или POST на `/tierlist` отвечает не 200, поменять ожидание на фактический код — проверяется только отсутствие перенаправления.

Остановка: `taskkill //PID $(cat <SCRATCH>/httpd.pid) //F`.

- [ ] **Step 5: Commit**

```bash
git add public_html/.htaccess tests/i18n_routes_test.php
git commit -m "feat(i18n): /en/… в .htaccess и автоопределение языка

Приставка снимается внутренним рероутом только у известных разделов.
Адрес без приставки уводится 302 на язык из куки nx_lang, иначе из
Accept-Language: языки СНГ остаются на русском, прочие получают английский.
Боты, POST и внутренние рероуты не перенаправляются. Проверено на
локальном Apache; порядок относительно кеша LiteSpeed проверить на бою.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Проверка в браузере, финальный прогон, PR

**Files:**
- Create: `tools/check-i18n-ui.mjs`

- [ ] **Step 1: Playwright в worktree**

```bash
[ -d tools/node_modules ] || cp -r "C:/Users/nural/Desktop/Nexus Tier List/tools/node_modules" tools/node_modules
```
(`tools/node_modules` в `.gitignore`.) Локальный Apache из Task 6 должен быть запущен.

- [ ] **Step 2: Скрипт проверки**

`tools/check-i18n-ui.mjs`:

```js
// Проверка языков в настоящем браузере через локальный Apache (Task 6 плана
// docs/superpowers/plans/2026-10-20-multilang-pr1.md): автоопределение,
// переключение чипами, кука, перенос прежнего выбора из localStorage.
//   node tools/check-i18n-ui.mjs <папка-для-скриншотов>
// maknemy.com направляется на 127.0.0.1:8471, чтобы сработали правила
// канонического хоста; X-Forwarded-Proto: https снимает редирект на https.
import { chromium } from 'playwright';
import fs from 'node:fs';

const out = process.argv[2] || '.';
fs.mkdirSync(out, { recursive: true });
const ORIGIN = 'http://maknemy.com';
const browser = await chromium.launch({
  channel: 'chrome',
  args: ['--host-resolver-rules=MAP maknemy.com 127.0.0.1:8471', '--disable-features=HttpsUpgrades'],
});
const fails = [];
const ok = (cond, msg) => { console.log((cond ? 'ok   ' : 'FAIL ') + msg); if (!cond) fails.push(msg); };

async function ctx(locale) {
  const c = await browser.newContext({ locale, extraHTTPHeaders: { 'X-Forwarded-Proto': 'https' }, viewport: { width: 1280, height: 900 } });
  await c.route('**/api/**', r => r.fulfill({ status: 200, contentType: 'application/json', body: '{}' }));
  const p = await c.newPage();
  p.setDefaultNavigationTimeout(45000);
  p.on('pageerror', e => { if (/i18n|I18N|siteUrl|url is not/i.test(String(e))) fails.push('pageerror: ' + e); console.log('pageerror:', String(e)); });
  return [c, p];
}
const lang = p => p.evaluate(() => document.documentElement.lang);
const cookie = async c => ((await c.cookies(ORIGIN)).find(x => x.name === 'nx_lang') || {}).value;

{
  const [c, p] = await ctx('de-DE');
  await p.goto(ORIGIN + '/tierlist');
  ok(new URL(p.url()).pathname === '/en/tierlist', 'de-браузер попал на /en/tierlist: ' + p.url());
  ok(await lang(p) === 'en', 'html lang=en');
  ok(await p.locator('a[href="/en/calculator"]').count() > 0, 'шапка ведёт на /en/calculator');
  ok(await p.locator('link[hreflang="ru"]').count() === 1, 'hreflang ru');
  await p.screenshot({ path: `${out}/en-tierlist.png` });

  await p.click('#langSwitch [data-lang="ru"]');
  await p.waitForURL(u => new URL(u).pathname === '/tierlist');
  ok(await lang(p) === 'ru', 'RU-чип: html lang=ru');
  ok(await cookie(c) === 'ru', 'RU-чип: кука nx_lang=ru');

  await p.goto(ORIGIN + '/calculator');
  ok(new URL(p.url()).pathname === '/calculator', 'кука ru сильнее de-браузера');

  await p.click('#langSwitch [data-lang="en"]');
  await p.waitForURL(u => new URL(u).pathname === '/en/calculator');
  ok(await cookie(c) === 'en', 'EN-чип: кука nx_lang=en');

  await p.goto(ORIGIN + '/');
  ok(new URL(p.url()).pathname === '/en', 'кука en уводит корень на /en');
  await p.screenshot({ path: `${out}/en-home.png` });
  await c.close();
}

{
  const [c, p] = await ctx('ru-RU');
  await p.goto(ORIGIN + '/tierlist');
  ok(new URL(p.url()).pathname === '/tierlist', 'ru-браузер остаётся на русском');
  await p.evaluate(() => localStorage.setItem('nexus-lang-v1', 'en'));
  await p.goto(ORIGIN + '/news');
  await p.waitForURL(u => new URL(u).pathname === '/en/news');
  ok(true, 'прежний выбор en из localStorage перенёс на /en/news');
  ok(await cookie(c) === 'en', 'перенос поставил куку en');
  ok(await p.evaluate(() => localStorage.getItem('nexus-lang-v1')) === null, 'старый ключ удалён');
  await c.close();
}

await browser.close();
if (fails.length) { console.log(`\n${fails.length} FAIL`); process.exit(1); }
console.log('\nALL OK');
```

- [ ] **Step 3: Запуск**

Run: `node tools/check-i18n-ui.mjs $SNAP/ui`
Expected: все строки `ok`, в конце `ALL OK`. `pageerror` про сеть или пустые ответы API — шум (API подменено пустым JSON); провалом считаются только ошибки про `i18n`/`url`. Скриншоты `$SNAP/ui/en-tierlist.png` и `$SNAP/ui/en-home.png` открыть и посмотреть глазами: тексты английские, вёрстка не съехала.

Остановить Apache, удалить `$WT/config.php`.

- [ ] **Step 4: Финальный прогон и аудит версий**

```bash
for t in tests/*_test.php; do r=$(/c/xampp/php/php.exe -d extension=gd "$t" 2>&1 | tail -1); echo "$t: $r"; done | grep -v ' 0 failures'
for t in tests/*_test.mjs; do PHP=/c/xampp/php/php.exe node --test "$t" >/dev/null 2>&1 || echo "FAIL $t"; done
/c/xampp/php/php.exe tools/i18n_build.php --check && echo BUILD-FRESH
git fetch -q maknemy
for f in $(git diff --name-only maknemy/master...HEAD -- public_html/js public_html/css | grep -E '\.(js|css)$' | grep -v '^public_html/js/i18n/'); do
  n=$(basename "$f"); d=$(dirname "${f#public_html/}")
  echo "$n: master=$(git grep -ohE "$d/$n\?v=[0-9]+" maknemy/master -- public_html | sort -u | tr '\n' ' ') branch=$(git grep -ohE "$d/$n\?v=[0-9]+" HEAD -- public_html | sort -u | tr '\n' ' ')"
done
```
Expected: только известные падения (3 + 1), node без `FAIL`, `BUILD-FRESH`; у каждого изменённого js номер в ветке больше, чем в master, и один на всех страницах. Если с начала работы в master влетели PR с теми же файлами — смержить master в ветку (`git merge maknemy/master`), разрешить конфликты номером выше обоих и повторить шаг.

- [ ] **Step 5: Push и PR**

```bash
git push -u maknemy feat/i18n-multilang
```
Если push висит больше 20 секунд — повторить с `-c http.sslbackend=schannel -c http.curloptResolve=github.com:443:<живой IP github.com>`.

```bash
gh pr create --repo kannurali/MaknemyTierlist --base master --head feat/i18n-multilang \
  --title "Мультиязычность, часть 1: /en/… на сервере, автоопределение языка" \
  --body-file $SNAP/../pr-body.md
```

`$SNAP/../pr-body.md`:

```markdown
Первая часть мультиязычного сайта по спеке `docs/superpowers/specs/2026-10-19-multilang-design.md`.

**Что меняется**
- Английский теперь на своих адресах: `/en`, `/en/tierlist`, `/en/news/12`… Сервер сразу отдаёт страницу по-английски: тексты, title, description, og, `<html lang>`.
- Русский остаётся на прежних адресах; русская разметка совпадает с прежней байт в байт, кроме тега словаря, hreflang и `<base>` у тирлиста.
- Первый заход на адрес без приставки: браузер на языке СНГ — русский, любой другой — `/en/…`. Выбор в чипах RU/EN запоминается в куке `nx_lang` и важнее браузера. Боты не перенаправляются.
- Словари интерфейса — `public_html/lang/*.json`; для JS собираются в `js/i18n/<язык>.js` (`php tools/i18n_build.php`), sitemap — оттуда же, с hreflang.
- Правовые страницы двуязычные и остаются на `/privacy`, `/terms`.

**После мержа проверить на бою**
1. Кеш не мешает автоопределению:
   `curl -s -o /dev/null -w '%{http_code}\n' -H 'Accept-Language: ru' https://maknemy.com/tierlist` (прогрев) → `200`, затем
   `curl -sI -H 'Accept-Language: de-DE' https://maknemy.com/tierlist | grep -i '^location'` → `…/en/tierlist`.
   Если пришло `200` без location — кеш LiteSpeed отвечает раньше правила: закомментировать блок «Языки» до строк `RewriteRule ^(en)…` и написать мне — переносим автоопределение в JS.
2. Кеш разделяет языки: `curl -s https://maknemy.com/en/tierlist | grep -o '<html lang="[a-z]*">'` → `en` дважды подряд (второй — `x-litespeed-cache: hit`), и `curl -s https://maknemy.com/tierlist | grep -o '<html lang="[a-z]*">'` → `ru`.
3. `curl -s https://maknemy.com/ | grep hreflang` — три строки.
4. Search Console: отправить `https://maknemy.com/sitemap.xml` заново.

Миграций нет.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
```

После создания: `mcp__ccd_pr__get_status`, при необходимости `bind_pr`.

---

## Self-review

- Спека §1 (адреса) → Task 6; §2 (сервер, словари, `t()`, шаблоны, голова, ссылки, sitemap) → Tasks 1, 2, 4, 5; §3 (JS, словарь отдельным файлом, переход по выбору, перенос из localStorage) → Task 3; §4 (автоопределение, кука, переключатель-чипы) → Tasks 3, 6, 7; §8 (проверки PR 1) → тесты в каждой задаче, снимки, Apache, Playwright, пункты «на бою» в PR. §5–6 (контент, шрифты) — PR 2/3, вне этого плана.
- Имена сквозные: `i18n_lang/i18n_dict/t/te/ta/u/i18n_url/i18n_og_locale/i18n_alternates/i18n_dict_tag`, `nx_render_page`, `i18n_flatten`, `i18n_source`, `I18N.url/pageLang/pickLang/go/stripPrefix`, `siteUrl` (локальный помощник в JS-файлах).
