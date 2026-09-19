# Вход через Roblox по приглашению — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** до одобрения OAuth-приложения кнопку «Войти через Roblox» видят только пришедшие по ссылке `https://maknemy.com/?signin`; ключ `roblox_login_public` в `config.php` открывает её всем.

**Architecture:** сервер отдаёт в `/api/session.php` новое поле `roblox_public` (функция `roblox_login_public()` в `api/lib/roblox_oauth.php`). `js/topbar.js` разбирает приглашение из адреса, запоминает его в `localStorage` и превращает заглушку в ссылку входа только при `roblox_public` или приглашении. Эндпоинты входа не меняются.

**Tech Stack:** PHP 7.4+ без зависимостей, ES5 в `public_html/js`, свой PHP-раннер тестов (`tests/lib.php`), Playwright 1.62.1 для браузерной проверки.

**Спека:** [2026-09-17-roblox-login-invite-design.md](../specs/2026-09-17-roblox-login-invite-design.md)

## Global Constraints

- Worktree `C:/Users/nural/AppData/Local/Temp/claude/C--Users-nural-Desktop-Nexus-Tier-List/4cbb66ef-780a-4fdb-aeb0-0ca8d2873812/scratchpad/wt-invite`, ветка `feat/roblox-login-invite` от `maknemy/master` (`2e531e2`, мерж #73). Главное дерево не трогать: там параллельно работает владелец.
- В `public_html/js/*.js` и разметке страниц — без комментариев; PHP-комментарии можно.
- Правки существующих файлов — через `$SP/patch.py` (CRLF, якорь ровно один раз, без NUL); после — нет `Bin` в `git diff --stat`.
- Ключ конфига: `roblox_login_public`, по умолчанию `false`. Поле ответа: `roblox_public`. Ключ `localStorage`: `nexus-signin-v1` = `"1"`. Параметр: `signin`, регулярка `/([?&])signin(=[^&]*)?(&|$)/`.
- `topbar.js?v=` 5 → 8 на `home.php`, `index.php`, `news.php`, `calculator.php` (PR #70 поднимает до 7).
- PHP-тесты: `$SP/php-gd.sh` (XAMPP с `-d extension=gd`); node-тесты — настоящий `C:/xampp/php/php.exe` в `$PHP`.
- Поставка: `git push -u maknemy feat/roblox-login-invite`, PR в `master` репозитория `kannurali/MaknemyTierlist`; мержит владелец.

```bash
WT=/c/Users/nural/AppData/Local/Temp/claude/C--Users-nural-Desktop-Nexus-Tier-List/4cbb66ef-780a-4fdb-aeb0-0ca8d2873812/scratchpad/wt-invite
SP=/c/Users/nural/AppData/Local/Temp/claude/C--Users-nural-Desktop-Nexus-Tier-List/4cbb66ef-780a-4fdb-aeb0-0ca8d2873812/scratchpad
```

Скрипты правок лежат в `$SP` и начинаются одинаково:

```python
from patch import apply

WT = 'C:/Users/nural/AppData/Local/Temp/claude/C--Users-nural-Desktop-Nexus-Tier-List/4cbb66ef-780a-4fdb-aeb0-0ca8d2873812/scratchpad/wt-invite/'
```

---

### Task 1: Ключ `roblox_login_public` и поле `roblox_public`

**Files:**
- Modify: `public_html/api/lib/roblox_oauth.php` (после `roblox_oauth_enabled`)
- Modify: `public_html/api/session.php` (докблок `handle_session`, массив ответа)
- Modify: `config.sample.php` (после `roblox_redirect_uri`)
- Test: `tests/roblox_auth_test.php` (после теста «полный конфиг включает вход» и после «вход выключен — шапка узнаёт об этом»)

**Interfaces:**
- Consumes: `roblox_oauth_enabled(array $cfg): bool`.
- Produces: `roblox_login_public(array $cfg): bool`; ответ `/api/session.php` — `{admin: bool, user: ?object, roblox: bool, roblox_public: bool}`. Задача 2 читает `s.roblox_public`.

- [ ] **Step 1: Тесты** — `$SP/t1_test.py` (шапка скрипта — см. выше):

```python
apply(WT + 'tests/roblox_auth_test.php', [
(
r'''    assert_true(roblox_oauth_enabled($cfg), 'включён');
    assert_eq('123', roblox_oauth_config($cfg)['client_id'], 'пробелы обрезаны');
});
''',
r'''    assert_true(roblox_oauth_enabled($cfg), 'включён');
    assert_eq('123', roblox_oauth_config($cfg)['client_id'], 'пробелы обрезаны');
});

// Пока приложение в Roblox не прошло ревью, войти могут только 10 разных
// аккаунтов. Кнопка, видимая всем, раздала бы эти места первым встречным,
// поэтому всем вход открывает только явный ключ в config.php, а до него
// кнопку видят пришедшие по ссылке с ?signin (js/topbar.js).
test('вход открыт всем только явным ключом', function () {
    $on = [
        'roblox_client_id' => '1', 'roblox_client_secret' => '2',
        'roblox_redirect_uri' => 'https://maknemy.com/api/roblox_callback.php',
    ];
    assert_eq(false, roblox_login_public($on), 'ключа нет — только по приглашению');
    assert_eq(true, roblox_login_public($on + ['roblox_login_public' => true]), 'true');
    assert_eq(false, roblox_login_public($on + ['roblox_login_public' => false]), 'false');
    // Конфиг правят руками в cPanel, и кавычки вокруг значения не должны
    // его переворачивать: !empty('false') открыл бы вход.
    assert_eq(true, roblox_login_public($on + ['roblox_login_public' => 'true']), "'true'");
    assert_eq(false, roblox_login_public($on + ['roblox_login_public' => 'false']), "'false'");
    assert_eq(true, roblox_login_public($on + ['roblox_login_public' => 1]), '1');
    assert_eq(false, roblox_login_public($on + ['roblox_login_public' => '0']), "'0'");
    assert_eq(false, roblox_login_public($on + ['roblox_login_public' => '']), 'пустая строка');
    assert_eq(false, roblox_login_public(['roblox_login_public' => true]),
        'без ключей приложения открывать нечего');
});

// Образец копируют на бой как есть — вход в нём обязан стоять закрытым.
test('в образце конфига вход закрыт для всех', function () {
    $sample = require __DIR__ . '/../config.sample.php';
    assert_true(array_key_exists('roblox_login_public', $sample), 'ключ описан в образце');
    assert_eq(false, $sample['roblox_login_public'] ?? null, 'и равен false');
});
'''),
(
r'''test('вход выключен — шапка узнаёт об этом', function () {
    $s = handle_session(function () { return test_db(); }, [], []);
    assert_eq(false, $s['roblox'], 'client_id не прописан');
});
''',
r'''test('вход выключен — шапка узнаёт об этом', function () {
    $s = handle_session(function () { return test_db(); }, [], []);
    assert_eq(false, $s['roblox'], 'client_id не прописан');
});

test('шапка узнаёт, открыт ли вход всем', function () use ($CFG_ON) {
    $db = function () { return test_db(); };
    assert_eq(false, handle_session($db, [], $CFG_ON)['roblox_public'] ?? null,
        'по умолчанию — только по приглашению');
    assert_eq(true, handle_session($db, [], $CFG_ON + ['roblox_login_public' => true])['roblox_public'] ?? null,
        'ключ открыл вход');
    assert_eq(false, handle_session($db, [], ['roblox_login_public' => true])['roblox_public'] ?? null,
        'без приложения открывать нечего');
});
'''),
])
```

- [ ] **Step 2: Красный**

Run: `cd $SP && python t1_test.py && cd $WT && $SP/php-gd.sh tests/roblox_auth_test.php`
Expected: `ERROR - вход открыт всем только явным ключом: Call to undefined function roblox_login_public()`, `ASSERT FAIL ключ описан в образце`, три `ASSERT FAIL` в «шапка узнаёт, открыт ли вход всем»; код 1.

- [ ] **Step 3: Реализация** — `$SP/t1_impl.py`:

```python
apply(WT + 'public_html/api/lib/roblox_oauth.php', [(
r'''function roblox_oauth_enabled(array $cfg): bool {
    return roblox_oauth_config($cfg) !== [];
}
''',
r'''function roblox_oauth_enabled(array $cfg): bool {
    return roblox_oauth_config($cfg) !== [];
}

/**
 * Открыт ли вход всем посетителям.
 *
 * Пока приложение в Roblox не прошло ревью, войти могут только 10 разных
 * аккаунтов, и кнопка, видимая всем, раздала бы эти места первым встречным.
 * Поэтому по умолчанию её видят только пришедшие по ссылке с ?signin
 * (js/topbar.js), а после одобрения в config.php ставится
 * roblox_login_public => true.
 *
 * FILTER_VALIDATE_BOOLEAN, а не !empty(): конфиг правят руками в cPanel, и
 * строка 'false' в кавычках не должна молча открыть вход.
 */
function roblox_login_public(array $cfg): bool {
    if (!roblox_oauth_enabled($cfg)) { return false; }
    return filter_var($cfg['roblox_login_public'] ?? false, FILTER_VALIDATE_BOOLEAN);
}
''')])

apply(WT + 'public_html/api/session.php', [
(
r''' *   roblox — заведено ли вообще приложение в Roblox. По нему шапка решает,
 *            показывать кнопку входа или оставить прежнюю заглушку: пока
 *            client_id не прописан, кнопка вела бы на 503.
''',
r''' *   roblox — заведено ли вообще приложение в Roblox. По нему шапка решает,
 *            показывать кнопку входа или оставить прежнюю заглушку: пока
 *            client_id не прописан, кнопка вела бы на 503.
 *   roblox_public — открыт ли вход всем. Пока нет, кнопку видят только
 *            пришедшие по ссылке с ?signin (см. roblox_login_public()).
'''),
(
r'''        'admin'  => !empty($session['admin']),
        'user'   => $user,
        'roblox' => roblox_oauth_enabled($cfg),
''',
r'''        'admin'         => !empty($session['admin']),
        'user'          => $user,
        'roblox'        => roblox_oauth_enabled($cfg),
        'roblox_public' => roblox_login_public($cfg),
'''),
])

apply(WT + 'config.sample.php', [(
r'''    'roblox_redirect_uri'  => 'https://maknemy.com/api/roblox_callback.php',
''',
r'''    'roblox_redirect_uri'  => 'https://maknemy.com/api/roblox_callback.php',
    // Кнопку входа видят все посетители только при true. Пока приложение в
    // Roblox не прошло ревью, войти могут лишь 10 разных аккаунтов, поэтому
    // до одобрения здесь false: кнопку показывают только тем, кто открыл
    // сайт по ссылке https://maknemy.com/?signin (она же — Entry Link).
    'roblox_login_public'  => false,
''')])
```

- [ ] **Step 4: Зелёный**

Run: `cd $SP && python t1_impl.py && cd $WT && $SP/php-gd.sh tests/roblox_auth_test.php && $SP/php-gd.sh tests/auth_test.php && $SP/php-gd.sh tests/bootstrap_test.php`
Expected: `0 failures` у всех трёх.

- [ ] **Step 5: Коммит**

```bash
cd $WT && git diff --stat && git add public_html/api/lib/roblox_oauth.php public_html/api/session.php config.sample.php tests/roblox_auth_test.php docs/superpowers/specs/2026-09-17-roblox-login-invite-design.md docs/superpowers/plans/2026-09-17-roblox-login-invite.md
git commit -m "feat(auth): ключ roblox_login_public и поле roblox_public в session.php"
```

---

### Task 2: Приглашение в шапке

**Files:**
- Modify: `public_html/js/topbar.js` (перед `function toLoginLink`, обработчик ответа `/api/session.php`)
- Modify: `public_html/home.php`, `public_html/index.php`, `public_html/news.php`, `public_html/calculator.php` (`topbar.js?v=5` → `?v=8`)
- Create: `tools/check-signin-invite-ui.mjs`
- Test: `tests/topbar_test.php` (новый раздел перед `run_tests();`)

**Interfaces:**
- Consumes: поле `roblox_public` из задачи 1.
- Produces: `takeInvite(): boolean` внутри IIFE `topbar.js`; ключ `nexus-signin-v1`.

- [ ] **Step 1: Текстовый тест** — `$SP/t2_test.py`:

```python
apply(WT + 'tests/topbar_test.php', [(
r'''        'клик после протяжки глушится по тому же порогу');
});

run_tests();
''',
r'''        'клик после протяжки глушится по тому же порогу');
});

// --------------------------------------------------------------------------
//  Вход по приглашению
// --------------------------------------------------------------------------

// Пока приложение в Roblox не прошло ревью, войти могут только 10 разных
// аккаунтов. Поэтому кнопку входа видят пришедшие по ссылке с ?signin, а всем
// остальным её открывает ключ roblox_login_public (api/session.php отдаёт его
// как roblox_public). Поведение гоняет tools/check-signin-invite-ui.mjs, здесь
// проверяется, что правило не пропало из кода.
test('кнопка входа — только по приглашению или когда вход открыт всем', function () use ($PUB) {
    $js = top_read($PUB . '/js/topbar.js');
    assert_true(strpos($js, 'else if (s.roblox_public || invited) toLoginLink(avatarBtn);') !== false,
        'ссылка входа только при открытом входе или по приглашению');
    assert_eq(0, preg_match('/else toLoginLink\(avatarBtn\);/', $js),
        'безусловной ссылки входа остаться не должно');
    assert_true(strpos($js, 'var INVITE_KEY = "nexus-signin-v1";') !== false,
        'приглашение запоминается под своим ключом');
    assert_true(strpos($js, 'var INVITE_RE = /([?&])signin(=[^&]*)?(&|$)/;') !== false,
        'приглашение — параметр signin целиком, а не любое слово с ним');
});

// Шапка общая: страница со старым номером держала бы у вернувшегося
// посетителя прежнее правило показа кнопки.
test('js/topbar.js подключён одной версией на всех четырёх страницах', function () use ($PUB, $PAGES4) {
    $seen = [];
    foreach ($PAGES4 as $f) {
        preg_match('/src="js\/topbar\.js\?v=(\d+)"/', top_read($PUB . '/' . $f), $m);
        $seen[$f] = $m[1] ?? '';
    }
    assert_eq(1, count(array_unique($seen)), 'версии: ' . json_encode($seen));
});

run_tests();
''')])
```

- [ ] **Step 2: Браузерная проверка** — создать `tools/check-signin-invite-ui.mjs`:

```js
// Browser check for the Roblox sign-in invite (?signin).
//
//   node tools/check-signin-invite-ui.mjs [http://127.0.0.1:8885]
//
// Until Roblox approves the OAuth app only 10 accounts can sign in, so the
// header offers the login button to invited visitors only (a link with
// ?signin) unless config.php opens it to everyone (roblox_login_public).
// tests/topbar_test.php pins the rule as text; this drives the real header
// with every answer api/session.php can give, so it needs no Roblox app and
// no database. Every visitor is a fresh browser context: localStorage lives
// across pages inside one and never leaks into the next.
//
// Needs Playwright (install line in tools/make-mediakit-pdf.mjs) and a PHP
// server over public_html with a config.php next to it; home.php does not
// touch the database, so any DSN will do:
//
//   php -S 127.0.0.1:8885 -t public_html
import { chromium } from 'playwright';

const BASE = process.argv[2] || 'http://127.0.0.1:8885';
const START = '/api/roblox_start.php?return=';
const KEY = 'nexus-signin-v1';

const CLOSED = { admin: false, user: null, roblox: true, roblox_public: false };
const OPEN = { ...CLOSED, roblox_public: true };
const OFF = { admin: false, user: null, roblox: false, roblox_public: false };
const SIGNED = {
    ...CLOSED,
    user: { id: '42', name: 'mak', display: 'Mak', avatar: '', profile: 'https://www.roblox.com/users/42/profile' },
};

const browser = await chromium.launch();
let failed = 0;

function check(name, ok, detail) {
    console.log(`${ok ? 'ok  ' : 'FAIL'} - ${name}${ok ? '' : '   ' + JSON.stringify(detail)}`);
    if (!ok) failed++;
}

async function visitor(name, state, body, { noStorage = false } = {}) {
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'ru-RU' });
    const page = await context.newPage();
    page.setDefaultTimeout(10000);
    // php -S serves one connection at a time, and a speculative connection
    // Chromium opens without sending a request can hold it for ~10 s.
    page.setDefaultNavigationTimeout(45000);
    const errors = [];
    page.on('pageerror', e => errors.push(String(e)));
    await context.route(url => !url.href.startsWith(BASE), r => r.abort());
    await context.route('**/api/session.php*', r => r.fulfill({
        status: 200, contentType: 'application/json', body: JSON.stringify(state),
    }));
    if (noStorage) {
        await context.addInitScript(() => {
            Object.defineProperty(window, 'localStorage', {
                configurable: true,
                get() { throw new DOMException('denied', 'SecurityError'); },
            });
        });
    }
    try {
        await body(page);
        check(`${name}: без ошибок на странице`, errors.length === 0, errors);
    } catch (e) {
        check(`${name}: прогон`, false, String(e).split('\n')[0]);
    } finally {
        await context.close();
    }
}

// Открыть страницу и дождаться, пока шапка разберёт ответ о входе.
async function open(page, path) {
    const answered = page.waitForResponse(r => r.url().includes('/api/session.php'));
    await page.goto(BASE + path, { waitUntil: 'domcontentloaded' });
    await answered;
    await page.waitForTimeout(300);
    const avatar = await page.$eval('.mk-top .mk-avatar', el => ({
        tag: el.tagName.toLowerCase(),
        href: el.getAttribute('href'),
        soon: el.hasAttribute('data-soon'),
        menu: el.getAttribute('aria-haspopup'),
    }));
    return { ...avatar, url: page.url().slice(BASE.length) };
}

const stub = a => a.tag === 'button' && a.soon && a.href === null;
const loginTo = (a, ret) => a.tag === 'a' && a.href === START + encodeURIComponent(ret);
const stored = page => page.evaluate(k => {
    try { return localStorage.getItem(k); } catch (_) { return 'throws'; }
}, KEY);

await visitor('вход закрыт, приглашения нет', CLOSED, async page => {
    const a = await open(page, '/home.php');
    check('кнопка остаётся заглушкой', stub(a), a);
});

await visitor('приглашение по ссылке', CLOSED, async page => {
    let a = await open(page, '/home.php?signin');
    check('кнопка ведёт на вход с возвратом на страницу', loginTo(a, '/home.php'), a);
    check('параметр убран из адреса', a.url === '/home.php', a.url);
    const kept = await stored(page);
    check('приглашение запомнено', kept === '1', kept);
    a = await open(page, '/home.php');
    check('повторный заход без параметра — снова ссылка', loginTo(a, '/home.php'), a);
});

await visitor('вход открыт всем', OPEN, async page => {
    const a = await open(page, '/home.php');
    check('ссылка без приглашения', loginTo(a, '/home.php'), a);
});

await visitor('вход выключен', OFF, async page => {
    const a = await open(page, '/home.php?signin');
    check('заглушка даже по приглашению', stub(a), a);
    check('параметр всё равно убран', a.url === '/home.php', a.url);
});

await visitor('вошедший без приглашения', SIGNED, async page => {
    const a = await open(page, '/home.php');
    check('аватар с меню', a.tag === 'button' && !a.soon && a.menu === 'menu', a);
});

await visitor('приглашение среди других параметров', CLOSED, async page => {
    const a = await open(page, '/home.php?tab=1&signin=1#top');
    check('остальной адрес сохранён', a.url === '/home.php?tab=1#top', a.url);
    check('возврат туда же', loginTo(a, '/home.php?tab=1#top'), a);
});

await visitor('похожий параметр', CLOSED, async page => {
    const a = await open(page, '/home.php?signinx=1');
    check('не приглашение', stub(a), a);
    check('адрес не тронут', a.url === '/home.php?signinx=1', a.url);
    const kept = await stored(page);
    check('ничего не запомнено', kept === null, kept);
});

await visitor('хранилище недоступно', CLOSED, async page => {
    let a = await open(page, '/home.php?signin');
    check('ссылка по приглашению есть', loginTo(a, '/home.php'), a);
    a = await open(page, '/home.php');
    check('без параметра приглашение не пережило перезаход', stub(a), a);
}, { noStorage: true });

await browser.close();
console.log(failed ? `\n${failed} FAILED` : '\nALL OK');
process.exit(failed ? 1 : 0);
```

Окружение проверки (один раз): `config.php` в корне worktree (`<?php return ['dsn' => 'sqlite::memory:', 'db_user' => '', 'db_pass' => '', 'admin_hash' => '', 'images_dir' => __DIR__ . '/public_html/images'];`), `cd $WT/tools && npm init -y && npm install playwright@1.62.1`, сервер `nexus-invite` (`preview_start`, порт 8885).

- [ ] **Step 3: Красный**

Run: `cd $SP && python t2_test.py && cd $WT && $SP/php-gd.sh tests/topbar_test.php; cd $WT/tools && node check-signin-invite-ui.mjs`
Expected: `topbar_test.php` — `ASSERT FAIL ссылка входа только при открытом входе или по приглашению` и ещё три; браузер — `FAIL - кнопка остаётся заглушкой`, `FAIL - параметр убран из адреса`, `FAIL - не приглашение` и др., в конце `N FAILED`.

- [ ] **Step 4: Реализация** — `$SP/t2_impl.py`:

```python
apply(WT + 'public_html/js/topbar.js', [
(
r'''    else if (flag === "error") showToast(tx("user.error"));
  }

  function toLoginLink(btn) {
''',
r'''    else if (flag === "error") showToast(tx("user.error"));
  }

  var INVITE_KEY = "nexus-signin-v1";
  var INVITE_RE = /([?&])signin(=[^&]*)?(&|$)/;

  function takeInvite() {
    if (!INVITE_RE.test(location.search)) {
      try { return localStorage.getItem(INVITE_KEY) === "1"; } catch (_) { return false; }
    }
    try { localStorage.setItem(INVITE_KEY, "1"); } catch (_) {}
    if (window.history && history.replaceState) {
      var q = location.search.replace(INVITE_RE, "$1").replace(/[?&]$/, "");
      try { history.replaceState(null, "", location.pathname + q + location.hash); } catch (_) {}
    }
    return true;
  }

  function toLoginLink(btn) {
'''),
(
r'''      .then(function (s) {
        if (!s || !s.roblox) return;
        if (s.user) initUserMenu(avatarBtn, s.user);
        else toLoginLink(avatarBtn);
      })
''',
r'''      .then(function (s) {
        var invited = takeInvite();
        if (!s || !s.roblox) return;
        if (s.user) initUserMenu(avatarBtn, s.user);
        else if (s.roblox_public || invited) toLoginLink(avatarBtn);
      })
'''),
])

for page in ('home.php', 'index.php', 'news.php', 'calculator.php'):
    apply(WT + 'public_html/' + page, [(
        '<script src="js/topbar.js?v=5" defer></script>',
        '<script src="js/topbar.js?v=8" defer></script>',
    )])
```

- [ ] **Step 5: Зелёный**

Run: `cd $SP && python t2_impl.py && cd $WT && $SP/php-gd.sh tests/topbar_test.php && cd tools && node check-signin-invite-ui.mjs`
Expected: `0 failures`; браузер — все строки `ok`, в конце `ALL OK`.

- [ ] **Step 6: Коммит**

```bash
cd $WT && git diff --stat && grep -c 'topbar.js?v=8' public_html/home.php public_html/index.php public_html/news.php public_html/calculator.php
git add public_html/js/topbar.js public_html/home.php public_html/index.php public_html/news.php public_html/calculator.php tests/topbar_test.php tools/check-signin-invite-ui.mjs
git commit -m "feat(topbar): кнопка входа только по ссылке ?signin, пока вход не открыт всем"
```

---

### Task 3: README, полный прогон, PR

**Files:**
- Modify: `README.md` (раздел «Вход через Roblox»)

**Interfaces:**
- Consumes: всё из задач 1–2.
- Produces: PR в `master`.

- [ ] **Step 1: README** — `$SP/t3_readme.py`:

```python
apply(WT + 'README.md', [
(
'| `/api/session.php` | `{admin, user, roblox}` — по нему шапка решает, что показать |\n',
'| `/api/session.php` | `{admin, user, roblox, roblox_public}` — по нему шапка решает, что показать |\n',
),
(
r"""'roblox_redirect_uri'  => 'https://maknemy.com/api/roblox_callback.php',
```
""",
r"""'roblox_redirect_uri'  => 'https://maknemy.com/api/roblox_callback.php',
'roblox_login_public'  => false,
```
"""),
(
r'''разработке». Ничего выкатывать заново для включения не надо — правки конфига
достаточно.
''',
r'''разработке». Ничего выкатывать заново для включения не надо — правки конфига
достаточно.

### Вход по приглашению

Пока приложение в Roblox не опубликовано, войти могут только **10 разных
аккаунтов** — 11-й получит отказ от самого Roblox. Поэтому при
`'roblox_login_public' => false` кнопку входа видят не все, а только открывшие
сайт по ссылке с `?signin`:

```text
https://maknemy.com/?signin
```

Остальные видят прежнюю заглушку «В активной разработке», и места не
разбирают случайные посетители. `js/topbar.js` запоминает приглашение в
`localStorage` (`nexus-signin-v1`) и убирает `signin` из адреса: дальше по
сайту параметр не нужен, а скопированный адрес не разнесёт приглашение. Это
ограничение показа кнопки, а не доступа: `/api/roblox_start.php` приглашения
не проверяет.

После одобрения приложения в `config.php` ставится
`'roblox_login_public' => true` — и кнопку видят все. Выкатывать ничего не
нужно. Проверка в браузере: `node tools/check-signin-invite-ui.mjs`.
'''),
(
r'''Description, Entry Link (`https://maknemy.com`), Privacy Policy URL
(`https://maknemy.com/privacy`) и Terms of Service URL
(`https://maknemy.com/terms`), после чего **Edit and Publish → Review and
Publish → Submit for Review**.
''',
r'''Description, Entry Link (`https://maknemy.com/?signin` — см. «Вход по
приглашению»), Privacy Policy URL (`https://maknemy.com/privacy`) и Terms of
Service URL (`https://maknemy.com/terms`), после чего **Edit and Publish →
Review and Publish → Submit for Review**.

Форма ревью требует ещё две вещи:

* **Demo Video URL** — публичная ссылка на ролик до минуты (YouTube с
  доступом по ссылке подходит). Ролик начинается с посетителя, который ещё не
  вошёл, показывает нажатие кнопки входа, экран согласия Roblox и возврат на
  сайт уже вошедшим. Он обязан совпадать с данными заявки: снимать с Entry
  Link и показывать только то, что уже есть на сайте.
* **Category Justification** (до 1000 символов) — почему приложение в своей
  категории и зачем ему каждое разрешение. Категория — **Account Linking
  Tools**: по Creator Third Party App Policy ей разрешены ровно `openid` и
  `profile`, то есть всё, что просит сайт.
'''),
(
r'''Пока ревью идёт, приложение редактировать нельзя, и оно остаётся приватным.
После одобрения вернуть его в private уже не получится.
''',
r'''Пока ревью идёт, приложение редактировать нельзя, и оно остаётся приватным.
После одобрения вернуть его в private уже не получится, а в `config.php`
ставится `'roblox_login_public' => true`.
'''),
(
r'''заглушкой `data-soon`, а `js/topbar.js` по ответу `/api/session.php` делает из
неё либо ссылку «Войти через Roblox», либо аватар с меню («Профиль в Roblox»,
«Выйти»). Поэтому в PHP-страницах нет ни одного ветвления по входу — и четвёртой
копии условия тоже.
''',
r'''заглушкой `data-soon`, а `js/topbar.js` по ответу `/api/session.php` делает из
неё аватар с меню («Профиль в Roblox», «Выйти») для вошедшего, ссылку «Войти
через Roblox» — если вход открыт всем или посетитель пришёл по приглашению, а
иначе оставляет заглушкой. Поэтому в PHP-страницах нет ни одного ветвления по
входу — и четвёртой копии условия тоже.
'''),
])
```

Run: `cd $SP && python t3_readme.py`
Expected: `patched …README.md`.

- [ ] **Step 2: Полный прогон**

```bash
cd $WT && PHP=$SP/php-gd.sh bash tests/run_all.sh 2>&1 | grep -E '^== |failures|FAIL|ERROR' | grep -v ' 0 failures'
PHP=C:/xampp/php/php.exe; for t in tests/*_test.mjs; do node --test "$t" 2>&1 | grep -E '^# (pass|fail)'; done
cd tools && node check-signin-invite-ui.mjs && node check-item-glow-ui.mjs http://127.0.0.1:8885
```

Expected: ни одной строки с ненулевыми failures, у node `# fail 0` в каждом файле, обе браузерные проверки `ALL OK`.

- [ ] **Step 3: Чистота и совместимость с PR #70**

```bash
cd $WT && git diff --stat maknemy/master | grep Bin; git diff maknemy/master -- public_html/js | grep -E '^\+.*(//|/\*)'
git merge-tree --write-tree --name-only HEAD maknemy/feat/profile-and-chat
```

Expected: `Bin` и комментариев в JS нет. В `merge-tree` конфликтуют только строки `topbar.js?v=` на четырёх страницах (у #70 — 7, здесь — 8); `session.php`, `topbar.js`, тесты сливаются сами.

- [ ] **Step 4: Коммит и PR**

```bash
cd $WT && git add README.md && git commit -m "docs(auth): вход по приглашению и что требует форма ревью Roblox"
git push -u maknemy feat/roblox-login-invite
gh pr create --repo kannurali/MaknemyTierlist --base master --head feat/roblox-login-invite --title "feat(auth): вход через Roblox по ссылке ?signin до одобрения приложения" --body-file $SP/pr-body.md
```
