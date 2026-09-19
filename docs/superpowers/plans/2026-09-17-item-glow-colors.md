# Свечение предмета: золотой, зелёный, красный — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** админ выбирает для свечения предмета один из трёх цветов — золотой, ярко-зелёный, ярко-красный.

**Architecture:** поле `glow` хранит имя цвета; `glowOf(item)` в `app.js` разбирает значение (старое `true` — золото). Карточка и превью получают классы `glow glow-<цвет>`, а цвет в `filter` приходит из CSS-переменных `--glow-core`/`--glow-halo`, которые задают эти классы. Размеры, порядок правил относительно наведения и PNG не меняются.

**Tech Stack:** обычные HTML/CSS/JS без сборки, свой PHP-раннер тестов, `node --test`, Playwright (`tools/check-item-glow-ui.mjs`).

**Спека:** [2026-09-16-item-glow-design.md](../specs/2026-09-16-item-glow-design.md) (раздел про цвета). Первая версия — [2026-09-16-item-glow.md](2026-09-16-item-glow.md), PR #71.

## Global Constraints

- Worktree `C:/Users/nural/AppData/Local/Temp/claude/C--Users-nural-Desktop-Nexus-Tier-List/41188f68-515d-4365-80bf-11f007a4b460/scratchpad/wt-glow`, ветка `feat/item-glow-colors` от `maknemy/master` (`e2d348e`, мерж #71). Главное дерево пользователя не трогать.
- В `public_html/**` без пояснительных комментариев.
- CRLF в рабочем дереве: правки только через `$SP/patch.py` (точная замена, якорь ровно один раз); после правок в `git diff --stat` нет `Bin`.
- Значения `glow`: `"gold"`, `"green"`, `"red"` — свечение; `true` — золото; всё остальное — без свечения. Новый предмет — `glow: ""`. «Готово» пишет `getSeg("#mGlow")`.
- Список цветов в `app.js`: `const GLOW_COLORS = ["gold", "green", "red"];`
- Кнопки `#mGlow`: `data-v` `""`, `gold`, `green`, `red`; у цветных `class="seg-glow glow-<цвет>"`, `data-i18n="modal.glow<Цвет>"`, `data-i18n-title="modal.glowTitle"`.
- Ключи словаря: `modal.glowGold`, `modal.glowGreen`, `modal.glowRed`, `modal.glowTitle`, `modal.glow` — в `ru` и `en`; `modal.glowOn` удалить.
- Переменные: золото `.glow, .glow-gold { --glow-core: #ffe27f; --glow-halo: rgba(255,190,40,.95); }`, зелёный `.glow-green { --glow-core: #5dff6a; --glow-halo: rgb(0,255,64); }`, красный `.glow-red { --glow-core: #ff4a4a; --glow-halo: rgb(255,0,0); }` — золото объявлено первым.
- Размеры не меняются: компьютер `.15cqw`/`.45cqw`, телефон `.42cqw`/`1.27cqw`, превью `1.5px`/`4.5px`.
- Версии: `styles.css` 57 → 58, `app.js` 74 → 75, `i18n.js` 45 → 46 на `index.php`, `news.php`, `home.php`, `calculator.php`.
- Поставка: `git push -u maknemy feat/item-glow-colors`, PR в `master` `kannurali/MaknemyTierlist`; мержит пользователь.

## Подготовка

Всё из первого плана уже стоит и переиспользуется: `$SP/patch.py`, `config.php` в корне worktree, Playwright в `tools/`, обёртка `$SP/php-gd.sh`, запись `nexus-glow` в `.claude/launch.json` (порт 8880).

```bash
WT=/c/Users/nural/AppData/Local/Temp/claude/C--Users-nural-Desktop-Nexus-Tier-List/41188f68-515d-4365-80bf-11f007a4b460/scratchpad/wt-glow
SP=/c/Users/nural/AppData/Local/Temp/claude/C--Users-nural-Desktop-Nexus-Tier-List/41188f68-515d-4365-80bf-11f007a4b460/scratchpad
```

- [ ] В `$SP/patch.py` дописать замену куска между двумя метками (для переписывания двух тестов целиком):

```python
def replace_between(path, start, end, new):
    raw = open(path, 'rb').read()
    crlf = b'\r\n' in raw
    text = raw.decode('utf-8')
    if crlf:
        text = text.replace('\r\n', '\n')
    if text.count(start) != 1 or text.count(end) != 1:
        sys.exit(f'{path}: markers must occur exactly once')
    a = text.index(start)
    b = text.index(end)
    if b <= a:
        sys.exit(f'{path}: end marker before start marker')
    text = text[:a] + new + text[b:]
    if crlf:
        text = text.replace('\n', '\r\n')
    open(path, 'wb').write(text.encode('utf-8'))
    print('replaced', path)
```

- [ ] Запустить сервер: `preview_start` с именем `nexus-glow`.

---

### Task 1: Проверка в браузере под три цвета (сначала красная)

**Files:**
- Modify: `tools/check-item-glow-ui.mjs` (переписать целиком)

**Interfaces:**
- Consumes: сервер `http://127.0.0.1:8880`.
- Produces: `node tools/check-item-glow-ui.mjs [base]` → `ALL OK` и код 0, когда задачи 2–3 сделаны.

- [ ] **Step 1: Переписать проверку** — файл целиком:

```js
// Browser check for the item glow ("Выделение" in the item editor).
//
//   node tools/check-item-glow-ui.mjs [http://127.0.0.1:8880]
//
// tests/tags_test.php pins the markup, the dictionary and the CSS as text.
// This drives the real page: every glow colour on the card, the legacy
// `glow: true` left by the gold-only release (PR #71), the editor field and
// its live preview, the published payload, the colour under hover, the phone
// size, and that the PNG export still produces a file.
//
// The tier list comes from a fixture: api/tierlist.php, api/state.php,
// api/promo.php and api/save.php are answered here, so the check needs no
// database content and writes nothing. The admin role is granted the way
// admin.php grants it — window.NX_ADMIN_PAGE before app.js — plus a positive
// api/session.php, so no password is involved.
//
// Needs Playwright (install line in tools/make-mediakit-pdf.mjs) and a PHP
// server over public_html with a config.php next to it; any DSN will do,
// index.php survives an empty database:
//
//   php -S 127.0.0.1:8880 -t public_html
import { chromium } from 'playwright';
import { readFileSync } from 'node:fs';

const BASE = process.argv[2] || 'http://127.0.0.1:8880';
// Ядро свечения каждого цвета так, как его отдаёт getComputedStyle.
const CORE = { gold: '255, 226, 127', green: '93, 255, 106', red: '255, 74, 74' };
const COLORS = Object.keys(CORE);
const CYAN = '79, 214, 255';

const item = (id, value, extra = {}) => ({
    id, name: id, value, icon: 'assets/icon-sample.png', type: 'f', demand: 'green', trend: '',
    desc: '', descEn: '', terms: '', termsEn: '', tag: '', tagEn: '', flag: false, wip: false,
    ...extra,
});

// legacy — так сохранял PR #71, nope — его «выключено», weird — цвета нет в
// списке, old — сохранение, сделанное до появления поля.
const EXPECT = { gold: 'gold', green: 'green', red: 'red', legacy: 'gold', off: '', nope: '', weird: '', old: '' };
const FIXTURE = {
    title: 'MAKNEMY\nTIER LIST',
    date: '17.09.2026',
    autoSort: false,
    filters: { configurators: true, fruits: true, perms: false, passes: true },
    ad: { text: '', image: '', link: '' },
    tiers: [{
        id: 't1', label: 'MK', logo: 'assets/logo-mk.png',
        items: [
            item('gold', '8000', { glow: 'gold' }),
            item('green', '7000', { glow: 'green' }),
            item('red', '6000', { glow: 'red' }),
            item('legacy', '5000', { glow: true }),
            item('off', '4000', { glow: '' }),
            item('nope', '3000', { glow: false }),
            item('weird', '2000', { glow: 'blue' }),
            item('old', '1000'),
        ],
    }],
    _rev: 1,
};

const browser = await chromium.launch();
let failed = 0;

function check(name, ok, detail) {
    console.log(`${ok ? 'ok  ' : 'FAIL'} - ${name}${ok ? '' : '   ' + JSON.stringify(detail)}`);
    if (!ok) failed++;
}

async function session(name, { admin = false, mobile = false }, body) {
    const context = await browser.newContext({
        viewport: mobile ? { width: 390, height: 844 } : { width: 1440, height: 1000 },
        isMobile: mobile,
        hasTouch: mobile,
        locale: 'ru-RU',
        acceptDownloads: true,
    });
    const page = await context.newPage();
    page.setDefaultTimeout(10000);
    // php -S serves one connection at a time, and a speculative connection
    // Chromium opens without sending a request can hold it for ~10 s.
    page.setDefaultNavigationTimeout(45000);
    const saved = [];
    const errors = [];
    page.on('pageerror', e => errors.push(String(e)));
    const json = data => ({ status: 200, contentType: 'application/json', body: JSON.stringify(data) });
    await page.route('**/api/state.php*', r => r.fulfill(json({ rev: 1, likes: 0 })));
    await page.route('**/api/tierlist.php*', r => r.fulfill(json({ tierlist: FIXTURE, likes: 0 })));
    await page.route('**/api/promo.php*', r => r.fulfill(json({ v: 1, rev: 0, campaigns: [] })));
    await page.route('**/api/session.php*', r => r.fulfill(json({ admin })));
    await page.route('**/api/save.php*', r => {
        saved.push(JSON.parse(r.request().postData() || 'null'));
        return r.fulfill(json({ ok: true, rev: 2 }));
    });
    if (admin) await page.addInitScript(() => { window.NX_ADMIN_PAGE = true; });
    try {
        await page.goto(BASE + '/index.php', { waitUntil: 'domcontentloaded' });
        await page.waitForSelector('.cell[data-id="old"]');
        await body(page, saved);
        check(`${name}: без ошибок на странице`, errors.length === 0, errors);
    } catch (e) {
        check(`${name}: прогон`, false, String(e).split('\n')[0]);
    } finally {
        await context.close();
    }
}

const iconFilter = (page, id) =>
    page.$eval(`.cell[data-id="${id}"] .cell-icon img`, el => getComputedStyle(el).filter);
const cellWidth = (page, id) =>
    page.$eval(`.cell[data-id="${id}"]`, el => el.getBoundingClientRect().width);
// Только классы свечения, отсортированные: «glow glow-red» или пусто.
const glowClasses = (page, sel) =>
    page.$eval(sel, el => [...el.classList].filter(c => c === 'glow' || c.startsWith('glow-')).sort().join(' '));
const expectClasses = color => (color ? ['glow', 'glow-' + color].sort().join(' ') : '');
const coreOf = filter => COLORS.find(c => filter.includes(CORE[c])) || '';
// Размытие первого слоя (ядра) в px. Делённое на ширину ячейки, оно
// сравнимо между компьютером и телефоном.
const coreBlur = filter => {
    const m = /^drop-shadow\(rgba?\([^)]*\) 0px 0px ([\d.]+)px\)/.exec(filter);
    return m ? Number(m[1]) : NaN;
};

let deskRatio = NaN;

await session('компьютер', {}, async page => {
    for (const [id, color] of Object.entries(EXPECT)) {
        const cls = await glowClasses(page, `.cell[data-id="${id}"]`);
        const f = await iconFilter(page, id);
        check(`${id}: свечение «${color || 'нет'}»`, cls === expectClasses(color) && coreOf(f) === color, { cls, f });
    }
    deskRatio = coreBlur(await iconFilter(page, 'gold')) / (await cellWidth(page, 'gold'));

    for (const id of COLORS) {
        await page.hover(`.cell[data-id="${id}"]`);
        await page.waitForTimeout(300);
        const f = await iconFilter(page, id);
        check(`${id}: при наведении цвет не меняется`, coreOf(f) === id && !f.includes(CYAN), f);
    }
    await page.hover('.cell[data-id="off"]');
    await page.waitForTimeout(300);
    const plain = await iconFilter(page, 'off');
    check('обычный предмет при наведении голубой, как раньше', plain.includes(CYAN), plain);
});

await session('телефон', { mobile: true }, async page => {
    for (const [id, color] of Object.entries(EXPECT)) {
        const f = await iconFilter(page, id);
        check(`телефон: ${id} — свечение «${color || 'нет'}»`, color ? coreOf(f) === color : f === 'none', f);
    }
    const ratio = coreBlur(await iconFilter(page, 'gold')) / (await cellWidth(page, 'gold'));
    check('телефон: свечение заметнее, чем на компьютере', ratio > deskRatio * 1.3, { ratio, deskRatio });
});

await session('админка', { admin: true }, async (page, saved) => {
    await page.waitForSelector('#tbToggles:not([hidden])');
    await page.evaluate(() => {
        const t = document.querySelector('#editToggle');
        t.checked = true;
        t.dispatchEvent(new Event('change'));
    });
    await page.waitForSelector('#stage.editing');

    const selected = () => page.$eval('#mGlow button.active', b => b.dataset.v);
    const preview = () => glowClasses(page, '.icon-preview');
    const previewCore = async () => coreOf(await page.$eval('#mIconPreview', el => getComputedStyle(el).filter));
    const cardClasses = id => glowClasses(page, `.cell[data-id="${id}"]`);
    const openItem = async id => {
        await page.click(`.cell[data-id="${id}"]`);
        await page.waitForSelector('#modal:not([hidden])');
    };
    const pick = v => page.click(`#mGlow button[data-v="${v}"]`);

    await openItem('legacy');
    const buttons = await page.$$eval('#mGlow button', bs => bs.map(b => b.dataset.v));
    check('окно: кнопки «—» и три цвета', JSON.stringify(buttons) === JSON.stringify(['', ...COLORS]), buttons);
    for (const c of COLORS) {
        const color = await page.$eval(`#mGlow button[data-v="${c}"]`, b => getComputedStyle(b).color);
        check(`окно: кнопка ${c} подписана своим цветом`, color.includes(CORE[c]), color);
    }
    check('окно: старое true открывается «Золотым»', (await selected()) === 'gold', await selected());
    check('окно: превью золотое',
        (await preview()) === expectClasses('gold') && (await previewCore()) === 'gold', await preview());
    await pick('red');
    check('окно: «Красное» сразу перекрашивает превью',
        (await preview()) === expectClasses('red') && (await previewCore()) === 'red', await preview());
    await page.click('#modalClose');
    check('крестик без «Готово» ничего не меняет', (await cardClasses('legacy')) === expectClasses('gold'), await cardClasses('legacy'));

    await openItem('legacy');
    check('окно: превью сбрасывается при новом открытии', (await preview()) === expectClasses('gold'), await preview());
    await page.click('#mSave');
    check('«Готово» без правок оставляет старое true золотым', (await cardClasses('legacy')) === expectClasses('gold'), await cardClasses('legacy'));

    await openItem('weird');
    check('окно: неизвестный цвет открывается как «—»', (await selected()) === '' && (await preview()) === '', await selected());
    await page.click('#modalClose');

    await openItem('off');
    await pick('green');
    await page.click('#mSave');
    check('«Готово» зажигает зелёным', (await cardClasses('off')) === expectClasses('green'), await cardClasses('off'));

    await openItem('gold');
    await pick('');
    check('окно: «—» гасит превью', (await preview()) === '', await preview());
    await page.click('#mSave');
    check('«Готово» снимает свечение', (await cardClasses('gold')) === '', await cardClasses('gold'));

    await page.click('#btnSave');
    await page.waitForFunction(() => document.querySelector('#btnSave').classList.contains('clean'));
    const last = saved[saved.length - 1];
    const items = last ? last.tiers[0].items : [];
    const glowById = Object.fromEntries(items.map(i => [i.id, 'glow' in i ? i.glow : '(нет поля)']));
    const want = { gold: '', green: 'green', red: 'red', legacy: 'gold', off: 'green', nope: false, weird: 'blue', old: '(нет поля)' };
    check('публикация уносит строки цветов, нетронутые предметы как были',
        JSON.stringify(glowById) === JSON.stringify(want), glowById);

    const [download] = await Promise.all([
        page.waitForEvent('download', { timeout: 60000 }),
        page.click('#btnPng'),
    ]);
    const sig = readFileSync(await download.path()).subarray(0, 8).toString('hex');
    check('PNG по-прежнему скачивается', sig === '89504e470d0a1a0a', sig);
});

await browser.close();
console.log(failed ? `\n${failed} FAILED` : '\nALL OK');
process.exit(failed ? 1 : 0);
```

- [ ] **Step 2: Запустить — красная по нужной причине**

Run: `cd $WT && node tools/check-item-glow-ui.mjs`
Expected: `ok` у `off`, `nope`, `old`; `FAIL` у `gold`, `green`, `red`, `legacy` (у карточки только `glow` без класса цвета, зелёный и красный светятся золотом) и у `weird` (`"blue"` сейчас истина — карточка светится); телефон — `FAIL` у `green`/`red`/`weird`; `FAIL - админка: прогон` (нет кнопки `data-v="gold"`). Код выхода 1.

- [ ] **Step 3: Коммит**

```bash
cd $WT && git add tools/check-item-glow-ui.mjs && git commit -m "test(tierlist): проверка свечения под три цвета

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 2: Цвет в данных, кнопки в окне, классы на карточке

**Files:**
- Modify: `public_html/index.php` (кнопка `data-v="on"` → три цветные)
- Modify: `public_html/js/i18n.js` (`modal.glowOn` → три ключа, новая подсказка; `ru` и `en`)
- Modify: `public_html/js/app.js` (`GLOW_COLORS`, `glowOf`, `defaultState`, `renderCell`, `addItem`, `openModal`, `syncGlowPreview`, «Готово»)
- Test: `tests/tags_test.php` (первый из двух тестов свечения переписывается)

**Interfaces:**
- Consumes: ничего.
- Produces: `GLOW_COLORS`, `glowOf(item) → "gold"|"green"|"red"|""`; классы `glow glow-<цвет>` на `.cell` и `.icon-preview`; классы `seg-glow glow-<цвет>` на кнопках. Задача 3 задаёт по этим классам цвет.

- [ ] **Step 1: Переписать первый тест свечения** — `$SP/task2_test.py` заменяет в `tests/tags_test.php` кусок от `// Выделение — самостоятельный флаг предмета, как NEW и «?», но своим полем:` до начала старого CSS-теста (`// Свечение — золотой контур по форме иконки.`). CSS-тест переписывает задача 3 — так каждый коммит ветки остаётся зелёным.

```python
from patch import replace_between

WT = 'C:/Users/nural/AppData/Local/Temp/claude/C--Users-nural-Desktop-Nexus-Tier-List/41188f68-515d-4365-80bf-11f007a4b460/scratchpad/wt-glow/'

replace_between(
    WT + 'tests/tags_test.php',
    '// Выделение — самостоятельный флаг предмета, как NEW и «?», но своим полем:\n',
    '// Свечение — золотой контур по форме иконки.',
r'''// Выделение — самостоятельный флаг предмета, как NEW и «?», но своим полем:
// к тренду оно не относится, и в легенде его нет. Поле хранит цвет. Цвет,
// который забыли в разметке, словаре или GLOW_COLORS, теряется молча: админ
// жмёт кнопку, а предмет не светится или светится не тем.
test('выделение: цвета в модалке, словаре и app.js совпадают', function () use ($PUB) {
    $s    = tag_read($PUB . '/index.php');
    $from = strpos($s, '<div class="seg" id="mGlow">');
    assert_true($from !== false, 'нужен сегмент выделения');
    assert_true($from > strpos($s, '<div class="seg" id="mTrend">'), 'выделение стоит после тренда');
    $seg  = substr($s, (int)$from, strpos($s, '</div>', (int)$from) - (int)$from);
    preg_match_all('/data-v="([^"]*)"/', $seg, $v);
    assert_eq(['', 'gold', 'green', 'red'], $v[1], 'выключено и три цвета');
    assert_true(strpos($s, '<label data-i18n="modal.glow">') !== false, 'поле подписано');

    $i18n = tag_read($PUB . '/js/i18n.js');
    foreach (['gold', 'green', 'red'] as $c) {
        $key = 'modal.glow' . ucfirst($c);
        assert_true(strpos($seg, 'data-v="' . $c . '" class="seg-glow glow-' . $c . '" data-i18n="' . $key . '" data-i18n-title="modal.glowTitle"') !== false,
            "кнопка $c: свой класс цвета, подпись и подсказка");
        assert_eq(2, preg_match_all('/"' . preg_quote($key, '/') . '":/', $i18n), "$key нужен на обоих языках");
    }
    foreach (['modal.glow', 'modal.glowTitle'] as $key) {
        assert_eq(2, preg_match_all('/"' . preg_quote($key, '/') . '":/', $i18n), "$key нужен на обоих языках");
    }
    assert_eq(0, preg_match_all('/"modal\.glowOn":/', $i18n), 'кнопки «Свечение» без цвета больше нет');

    $js = tag_read($PUB . '/js/app.js');
    assert_true(strpos($js, 'const GLOW_COLORS = ["gold", "green", "red"];') !== false,
        'список цветов в app.js совпадает с кнопками');
    assert_true(strpos($js, 'if (item.glow === true) return "gold";') !== false,
        'true из первой версии — это золото');
    assert_true(strpos($js, 'return GLOW_COLORS.includes(item.glow) ? item.glow : "";') !== false,
        'неизвестный цвет — без свечения');
    assert_true(strpos($js, 'cell.className = glow ? "cell glow glow-" + glow : "cell";') !== false,
        'карточка получает классы цвета');
    assert_true(strpos($js, 'flag: false, wip: false, glow: "",') !== false, 'предметы шаблона без свечения');
    assert_true(strpos($js, 'flag: true, wip: false, glow: "",') !== false, 'новый предмет без свечения');
    assert_true(strpos($js, 'it.glow = getSeg("#mGlow");') !== false, 'сохранение пишет цвет строкой');
    assert_eq(0, preg_match_all('/=== "on"/', $js), 'старого значения "on" не осталось');
    assert_true(strpos($js, '["#mDemand", "#mTrend", "#mGlow"]') !== false,
        'щелчок по сегменту выделения переключает кнопки');
    assert_true(strpos($js, 'box.classList.remove("glow", ...GLOW_COLORS.map(c => "glow-" + c));') !== false,
        'превью снимает прежний цвет');
    assert_true(strpos($js, 'if (glow) box.classList.add("glow", "glow-" + glow);') !== false,
        'превью берёт выбранный цвет');
    assert_true(strpos($js, '$("#mGlow").addEventListener("click", syncGlowPreview);') !== false,
        'щелчок сразу обновляет превью');

    $a    = strpos($js, 'function openModal(');
    $b    = strpos($js, 'function closeModal(', (int)$a);
    assert_true($a !== false && $b !== false, 'нужны openModal и closeModal');
    $open = substr($js, (int)$a, (int)$b - (int)$a);
    assert_true(strpos($open, 'setSeg("#mGlow", glowOf(it));') !== false,
        'openModal ставит цвет через glowOf');
    assert_true(strpos($open, 'syncGlowPreview();') !== false,
        'openModal сбрасывает превью под открытый предмет');
});

''')
```

- [ ] **Step 2: Запустить — красный**

Run: `cd $SP && python task2_test.py && cd $WT && $SP/php-gd.sh tests/tags_test.php`
Expected: `ASSERT FAIL выключено и три цвета`, кнопки/ключи/`GLOW_COLORS`/`glowOf`, `старого значения "on" не осталось` (expected 0, got 2); старый CSS-тест пока зелёный; код 1.

- [ ] **Step 3: Правки** — `$SP/task2.py`:

```python
from patch import apply

WT = 'C:/Users/nural/AppData/Local/Temp/claude/C--Users-nural-Desktop-Nexus-Tier-List/41188f68-515d-4365-80bf-11f007a4b460/scratchpad/wt-glow/'
TITLE = 'Свечение вокруг предмета на тирлисте. В PNG не попадает'

apply(WT + 'public_html/index.php', [(
r'''            <button data-v="on" class="seg-glow" data-i18n="modal.glowOn" data-i18n-title="modal.glowTitle" title="Золотое свечение вокруг предмета на тирлисте. В PNG не попадает">✦ Свечение</button>
''',
f'''            <button data-v="gold" class="seg-glow glow-gold" data-i18n="modal.glowGold" data-i18n-title="modal.glowTitle" title="{TITLE}">✦ Золотое</button>
            <button data-v="green" class="seg-glow glow-green" data-i18n="modal.glowGreen" data-i18n-title="modal.glowTitle" title="{TITLE}">✦ Зелёное</button>
            <button data-v="red" class="seg-glow glow-red" data-i18n="modal.glowRed" data-i18n-title="modal.glowTitle" title="{TITLE}">✦ Красное</button>
''')])

apply(WT + 'public_html/js/i18n.js', [
(
r'''      "modal.glowOn":           "✦ Свечение",
      "modal.glowTitle":        "Золотое свечение вокруг предмета на тирлисте. В PNG не попадает",
''',
r'''      "modal.glowGold":         "✦ Золотое",
      "modal.glowGreen":        "✦ Зелёное",
      "modal.glowRed":          "✦ Красное",
      "modal.glowTitle":        "Свечение вокруг предмета на тирлисте. В PNG не попадает",
'''),
(
r'''      "modal.glowOn":           "✦ Glow",
      "modal.glowTitle":        "Gold glow around the item on the tier list. Not included in the PNG",
''',
r'''      "modal.glowGold":         "✦ Gold",
      "modal.glowGreen":        "✦ Green",
      "modal.glowRed":          "✦ Red",
      "modal.glowTitle":        "Glow around the item on the tier list. Not included in the PNG",
'''),
])

apply(WT + 'public_html/js/app.js', [
(
r'''      flag: false, wip: false, glow: false,
''',
r'''      flag: false, wip: false, glow: "",
'''),
(
r'''    return "fruits";
  }

  function normalizeFilters(saved, defaults) {
''',
r'''    return "fruits";
  }

  const GLOW_COLORS = ["gold", "green", "red"];
  function glowOf(item) {
    if (item.glow === true) return "gold";
    return GLOW_COLORS.includes(item.glow) ? item.glow : "";
  }

  function normalizeFilters(saved, defaults) {
'''),
(
r'''    cell.className = item.glow ? "cell glow" : "cell";
''',
r'''    const glow = glowOf(item);
    cell.className = glow ? "cell glow glow-" + glow : "cell";
'''),
(
r'''flag: true, wip: false, glow: false,
''',
r'''flag: true, wip: false, glow: "",
'''),
(
r'''    setSeg("#mGlow", it.glow ? "on" : "");
''',
r'''    setSeg("#mGlow", glowOf(it));
'''),
(
r'''  function syncGlowPreview() { $(".icon-preview").classList.toggle("glow", getSeg("#mGlow") === "on"); }
''',
r'''  function syncGlowPreview() {
    const glow = getSeg("#mGlow");
    const box = $(".icon-preview");
    box.classList.remove("glow", ...GLOW_COLORS.map(c => "glow-" + c));
    if (glow) box.classList.add("glow", "glow-" + glow);
  }
'''),
(
r'''    it.glow = getSeg("#mGlow") === "on";
''',
r'''    it.glow = getSeg("#mGlow");
'''),
])
```

`GLOW_COLORS` объявлен у `groupOf`, задолго до верхнеуровневого `render()` в конце файла, — обращения до инициализации нет.

Run: `cd $SP && python task2.py`
Expected: три `patched`.

- [ ] **Step 4: Тесты зелёные**

Run: `cd $WT && node --check public_html/js/app.js && $SP/php-gd.sh tests/tags_test.php && node --test tests/i18n_test.mjs`
Expected: `0 failures`; i18n `fail 0`. Старый CSS-тест ещё зелёный: CSS не менялся.

- [ ] **Step 5: Коммит**

```bash
cd $WT && git add public_html/index.php public_html/js/i18n.js public_html/js/app.js tests/tags_test.php && git commit -m "feat(tierlist): цвет свечения на выбор в окне предмета

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 3: Цвета через CSS-переменные

**Files:**
- Modify: `public_html/css/styles.css` (правило свечения на компьютере, превью, кнопка, правило на телефоне)
- Test: `tests/tags_test.php` (второй тест свечения переписывается)

**Interfaces:**
- Consumes: классы из задачи 2.
- Produces: итоговый вид; `tags_test.php` и браузерная проверка зелёные.

- [ ] **Step 1: Переписать CSS-тест** — `$SP/task3_test.py` заменяет кусок от `// Свечение — золотой контур по форме иконки.` до `// --------------------------------------------------------------------------\n//  Группировка фильтров`:

```python
from patch import replace_between

WT = 'C:/Users/nural/AppData/Local/Temp/claude/C--Users-nural-Desktop-Nexus-Tier-List/41188f68-515d-4365-80bf-11f007a4b460/scratchpad/wt-glow/'

replace_between(
    WT + 'tests/tags_test.php',
    '// Свечение — золотой контур по форме иконки.',
    '// --------------------------------------------------------------------------\n//  Группировка фильтров\n',
r'''// Свечение — контур по форме иконки. Размеры общие, цвет приходит из
// переменных: золото стоит и на голом .glow (класс без цвета из первой
// версии), зелёный и красный переопределяют его ниже. Правило свечения стоит
// ПОСЛЕ голубого правила наведения: специфичность у них одна, и иначе при
// наведении голубое перебивало бы цветное. На телефоне свои размеры:
// пропорциональное десктопному свечение там почти не видно.
test('свечение предмета: цвета через переменные, наведение не гасит, на телефоне сильнее', function () use ($PUB) {
    $css = str_replace("\r\n", "\n", tag_read($PUB . '/css/styles.css'));

    $gold  = strpos($css, '.glow, .glow-gold { --glow-core: #ffe27f; --glow-halo: rgba(255,190,40,.95); }');
    $green = strpos($css, '.glow-green { --glow-core: #5dff6a; --glow-halo: rgb(0,255,64); }');
    $red   = strpos($css, '.glow-red { --glow-core: #ff4a4a; --glow-halo: rgb(255,0,0); }');
    assert_true($gold !== false && $green !== false && $red !== false, 'три цвета заданы переменными');
    assert_true($gold < $green && $gold < $red, 'золото объявлено раньше и не перебивает остальные');
    assert_eq(1, substr_count($css, '#ffe27f'), 'золото записано в одном месте');

    $rule = '/\.cell\.glow \.cell-icon img \{\n\s*filter: drop-shadow\(0 0 ([\d.]+)cqw var\(--glow-core\)\) drop-shadow\(0 0 ([\d.]+)cqw var\(--glow-halo\)\);\n\s*\}/';
    assert_eq(2, preg_match_all($rule, $css, $m, PREG_OFFSET_CAPTURE), 'одно правило для компьютера, одно для телефона');
    if (count($m[0]) !== 2) { return; }

    $hover = strpos($css, '.cell:hover .cell-icon img {');
    $phone = strpos($css, '@media (max-width: 640px)');
    $none  = strpos($css, '  .cell-icon img { filter: none; }');
    assert_true($m[0][0][1] > $hover, 'правило свечения стоит после голубого наведения');
    assert_true($m[0][0][1] < $phone, 'правило для компьютера — вне телефонных блоков');
    assert_true($m[0][1][1] > $none, 'правило для телефона — после телефонного filter: none');
    assert_eq(['.15', '.42'], [$m[1][0][0], $m[1][1][0]], 'ядро: компьютер, телефон');
    assert_eq(['.45', '1.27'], [$m[2][0][0], $m[2][1][0]], 'ореол: компьютер, телефон');

    assert_true(strpos($css, '.icon-preview.glow img { filter: drop-shadow(0 0 1.5px var(--glow-core)) drop-shadow(0 0 4.5px var(--glow-halo)); }') !== false,
        'превью в окне светится выбранным цветом');
    assert_true(strpos($css, '.seg .seg-glow { color: var(--glow-core); text-shadow: 0 0 6px var(--glow-halo); }') !== false,
        'кнопка цвета подписана своим цветом');
});

''')
```

- [ ] **Step 2: Запустить — красный**

Run: `cd $SP && python task3_test.py && cd $WT && $SP/php-gd.sh tests/tags_test.php`
Expected: `ASSERT FAIL три цвета заданы переменными`, `золото записано в одном месте` (expected 1, got 4), `одно правило для компьютера, одно для телефона` (expected 2, got 0); код 1.

- [ ] **Step 3: Правки** — `$SP/task3.py`:

```python
from patch import apply

WT = 'C:/Users/nural/AppData/Local/Temp/claude/C--Users-nural-Desktop-Nexus-Tier-List/41188f68-515d-4365-80bf-11f007a4b460/scratchpad/wt-glow/'

apply(WT + 'public_html/css/styles.css', [
(
r'''.cell.glow .cell-icon img {
  filter: drop-shadow(0 0 .15cqw #ffe27f) drop-shadow(0 0 .45cqw rgba(255,190,40,.95));
}
''',
r'''.glow, .glow-gold { --glow-core: #ffe27f; --glow-halo: rgba(255,190,40,.95); }
.glow-green { --glow-core: #5dff6a; --glow-halo: rgb(0,255,64); }
.glow-red { --glow-core: #ff4a4a; --glow-halo: rgb(255,0,0); }
.cell.glow .cell-icon img {
  filter: drop-shadow(0 0 .15cqw var(--glow-core)) drop-shadow(0 0 .45cqw var(--glow-halo));
}
'''),
(
r'''.icon-preview.glow img { filter: drop-shadow(0 0 1.5px #ffe27f) drop-shadow(0 0 4.5px rgba(255,190,40,.95)); }
''',
r'''.icon-preview.glow img { filter: drop-shadow(0 0 1.5px var(--glow-core)) drop-shadow(0 0 4.5px var(--glow-halo)); }
'''),
(
r'''.seg .seg-glow { color: #ffe27f; text-shadow: 0 0 6px rgba(255,190,40,.8); }
''',
r'''.seg .seg-glow { color: var(--glow-core); text-shadow: 0 0 6px var(--glow-halo); }
'''),
(
r'''  .cell.glow .cell-icon img {
    filter: drop-shadow(0 0 .42cqw #ffe27f) drop-shadow(0 0 1.27cqw rgba(255,190,40,.95));
  }
''',
r'''  .cell.glow .cell-icon img {
    filter: drop-shadow(0 0 .42cqw var(--glow-core)) drop-shadow(0 0 1.27cqw var(--glow-halo));
  }
'''),
])
```

Run: `cd $SP && python task3.py`
Expected: `patched …styles.css`.

- [ ] **Step 4: Тесты зелёные**

Run: `cd $WT && $SP/php-gd.sh tests/tags_test.php && git diff --stat`
Expected: `0 failures`; без `Bin`.

- [ ] **Step 5: Браузерная проверка зелёная**

Run: `cd $WT && node tools/check-item-glow-ui.mjs`
Expected: все `ok`, `ALL OK`.

- [ ] **Step 6: Глазами** — `$SP/visual.mjs` (живые данные): второму, четвёртому и шестому предмету — `"gold"`, `"green"`, `"red"`; снимки компьютера, наведения, телефона, окна с «Красным» (подпись кнопок цветом, превью красное). Цвета должны совпасть с утверждённым макетом «Вариант 2».

- [ ] **Step 7: Коммит**

```bash
cd $WT && git add public_html/css/styles.css tests/tags_test.php && git commit -m "feat(tierlist): зелёное и красное свечение

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 4: Кэш, полный прогон, PR

**Files:**
- Modify: `public_html/index.php`, `public_html/news.php`, `public_html/home.php`, `public_html/calculator.php`

- [ ] **Step 1: Версии** — `$SP/task4.py`:

```python
from patch import apply

PUB = 'C:/Users/nural/AppData/Local/Temp/claude/C--Users-nural-Desktop-Nexus-Tier-List/41188f68-515d-4365-80bf-11f007a4b460/scratchpad/wt-glow/public_html/'

apply(PUB + 'index.php', [
    ('css/styles.css?v=57"', 'css/styles.css?v=58"'),
    ('js/i18n.js?v=45"', 'js/i18n.js?v=46"'),
    ('js/app.js?v=74"', 'js/app.js?v=75"'),
])
for page in ('news.php', 'home.php', 'calculator.php'):
    apply(PUB + page, [('js/i18n.js?v=45"', 'js/i18n.js?v=46"')])
```

Run: `cd $SP && python task4.py && cd $WT && git grep -n -E "i18n\.js\?v=|styles\.css\?v=|app\.js\?v=" -- public_html`
Expected: четыре `patched`; `i18n.js?v=46` на четырёх страницах, `styles.css?v=58`, `app.js?v=75`.

- [ ] **Step 2: Полный прогон**

```bash
cd $WT && for t in tests/*_test.php tests/harness_selfcheck.php; do $SP/php-gd.sh "$t" >/dev/null 2>&1 || echo "FAIL $t"; done
cd $WT && export PHP=C:/xampp/php/php.exe && for t in tests/*_test.mjs; do node --test "$t" >/dev/null 2>&1 || echo "FAIL $t"; done
cd $WT && node tools/check-item-glow-ui.mjs | tail -2
```

Expected: ни одной строки `FAIL`; `ALL OK`.

- [ ] **Step 3: Чистота** — `git status --short` показывает только четыре страницы; скан на NUL пуст; в `git diff --stat maknemy/master` нет `Bin`.

- [ ] **Step 4: Коммит**

```bash
cd $WT && git add public_html/index.php public_html/news.php public_html/home.php public_html/calculator.php && git commit -m "chore(cache): версии styles.css, app.js и i18n.js под цвета свечения

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

- [ ] **Step 5: Push и PR** — `git fetch maknemy`; если `maknemy/master` ушёл от `e2d348e` — `git merge maknemy/master`, заново шаг 2 и сверка `?v=` (открытый #70 держит `i18n.js` на 44; если он смержен раньше, наш номер обязан быть выше его). Затем:

```bash
cd $WT && git push -u maknemy feat/item-glow-colors
gh pr create --repo kannurali/MaknemyTierlist --base master --head feat/item-glow-colors --title "feat(tierlist): зелёное и красное свечение на выбор" --body-file $SP/pr-body-colors.md
```

`$SP/pr-body-colors.md` — что изменилось (поле хранит цвет, `true` = золото, кнопки, переменные, версии), чего нет (PNG, легенда, сервер), как проверено (тесты, браузерная проверка, скриншоты); последняя строка `🤖 Generated with [Claude Code](https://claude.com/claude-code)`.
