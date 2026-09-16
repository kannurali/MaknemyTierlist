# Значок «Перерассмотр цены» — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** значок «Перерассмотр цены» на карточке в тире становится в 37/27 раза шире стрелок (как в легенде) на компьютере и на телефоне, низ — на уровне стрелок.

**Architecture:** две CSS-строки в `styles.css`: исправленная десктопная `.cell .trend.tr-swap` и новая такая же в телефонном блоке. Код, разметка и данные не меняются.

**Tech Stack:** CSS, свой PHP-раннер тестов, Playwright для замера.

**Спека:** [2026-09-17-trend-swap-size-design.md](../specs/2026-09-17-trend-swap-size-design.md)

## Global Constraints

- Worktree `C:/Users/nural/AppData/Local/Temp/claude/C--Users-nural-Desktop-Nexus-Tier-List/41188f68-515d-4365-80bf-11f007a4b460/scratchpad/wt-glow`, ветка `fix/trend-swap-size` от `maknemy/master` (`9372059`, мерж #72).
- В `public_html/**` без пояснительных комментариев.
- Правки через `$SP/patch.py` (CRLF, якорь ровно один раз); после — нет `Bin` в `git diff --stat`.
- Компьютер: `.cell .trend.tr-swap { width: 1.74cqw; top: 3.46cqw; }`.
- Телефон: `  .cell .trend.tr-swap { width: 3.43cqw; top: 6.58cqw; }` сразу после `  .cell .trend { width: 2.5cqw; }`.
- `styles.css?v=` 58 → 59 в `index.php`.
- Поставка: `git push -u maknemy fix/trend-swap-size`, PR в `master`; мержит пользователь.

```bash
WT=/c/Users/nural/AppData/Local/Temp/claude/C--Users-nural-Desktop-Nexus-Tier-List/41188f68-515d-4365-80bf-11f007a4b460/scratchpad/wt-glow
SP=/c/Users/nural/AppData/Local/Temp/claude/C--Users-nural-Desktop-Nexus-Tier-List/41188f68-515d-4365-80bf-11f007a4b460/scratchpad
```

Сервер `nexus-glow` (`preview_start`, порт 8880) и `$SP/php-gd.sh` — из предыдущих планов.

---

### Task 1: Размер и положение значка

**Files:**
- Modify: `public_html/css/styles.css` (строка `.cell .trend.tr-swap`; телефонный блок после `.cell .trend { width: 2.5cqw; }`)
- Test: `tests/tags_test.php` (тест «значки тренда в ячейке — те же картинки, что в легенде», CSS-часть)

**Interfaces:**
- Consumes: ничего.
- Produces: итоговые размеры; задача 2 только поднимает версию.

- [ ] **Step 1: Переписать CSS-часть теста** — `$SP/swap_test.py`:

```python
from patch import apply

WT = 'C:/Users/nural/AppData/Local/Temp/claude/C--Users-nural-Desktop-Nexus-Tier-List/41188f68-515d-4365-80bf-11f007a4b460/scratchpad/wt-glow/'

apply(WT + 'tests/tags_test.php', [(
r'''    // Ниже по файлу лежит общее .trend.tr-swap для легенды и модалки той же
    // специфичности. Без своей строки у ячейки оно перебивало бы её, и круглый
    // значок выходил вдвое шире стрелок, наезжая на цену.
    $css = tag_read($PUB . '/css/styles.css');
    assert_true(strpos($css, '.cell .trend.tr-swap { width: 1.27cqw; }') !== false,
        'ячейке нужна своя ширина swap');
});
''',
r'''    // Ниже по файлу лежит общее .trend.tr-swap для легенды и модалки той же
    // специфичности, что .cell .trend, поэтому у ячейки своя строка. Ширина —
    // как в легенде: круглый значок 37×43 в 37/27 раза шире стрелок 27×32,
    // иначе при той же ширине он читается мельче них. Верх сдвинут так, чтобы
    // низ совпал с низом стрелок и значок не наезжал на цену. На телефоне
    // строка своя: десктопная специфичнее телефонного .cell .trend и держала
    // значок вдвое меньше стрелок.
    $css = str_replace("\r\n", "\n", tag_read($PUB . '/css/styles.css'));
    $n   = '([\d.]+)';
    assert_true((bool)preg_match('/\n\.cell \.trend \{\n\s*position: absolute; top: ' . $n . 'cqw; left: \.1cqw;\n\s*width: ' . $n . 'cqw;/', $css, $arrowD),
        'стрелка на компьютере');
    assert_true((bool)preg_match('/\n\.cell \.trend\.tr-swap \{ width: ' . $n . 'cqw; top: ' . $n . 'cqw; \}/', $css, $swapD),
        'своя строка перерассмотра на компьютере');
    assert_true((bool)preg_match('/\n  \.cell \.trend \{ top: ' . $n . 'cqw; \}/', $css, $topM),
        'верх стрелки на телефоне');
    assert_true((bool)preg_match('/\n  \.cell \.trend \{ width: ' . $n . 'cqw; \}/', $css, $widthM, PREG_OFFSET_CAPTURE),
        'ширина стрелки на телефоне');
    assert_true((bool)preg_match('/\n  \.cell \.trend\.tr-swap \{ width: ' . $n . 'cqw; top: ' . $n . 'cqw; \}/', $css, $swapM, PREG_OFFSET_CAPTURE),
        'своя строка перерассмотра на телефоне');
    if (!$arrowD || !$swapD || !$topM || !$widthM || !$swapM) { return; }
    assert_true($swapM[0][1] > $widthM[0][1], 'строка перерассмотра на телефоне стоит после ширины стрелки');

    $fits = function (string $where, float $arrowW, float $arrowTop, float $swapW, float $swapTop) {
        assert_true(abs($swapW / $arrowW - 37 / 27) < 0.01, "$where: перерассмотр в 37/27 раза шире стрелки");
        $arrowBottom = $arrowTop + $arrowW * 32 / 27;
        $swapBottom  = $swapTop + $swapW * 43 / 37;
        assert_true(abs($arrowBottom - $swapBottom) < 0.02, "$where: низ перерассмотра на уровне стрелки");
    };
    $fits('компьютер', (float)$arrowD[2], (float)$arrowD[1], (float)$swapD[1], (float)$swapD[2]);
    $fits('телефон', (float)$widthM[1][0], (float)$topM[1], (float)$swapM[1][0], (float)$swapM[2][0]);
});
''')])
```

- [ ] **Step 2: Запустить — красный**

Run: `cd $SP && python swap_test.py && cd $WT && $SP/php-gd.sh tests/tags_test.php`
Expected: `ASSERT FAIL своя строка перерассмотра на компьютере` и `… на телефоне` (у десктопной строки нет `top`, телефонной нет вовсе); код 1.

- [ ] **Step 3: Правки** — `$SP/swap_css.py`:

```python
from patch import apply

WT = 'C:/Users/nural/AppData/Local/Temp/claude/C--Users-nural-Desktop-Nexus-Tier-List/41188f68-515d-4365-80bf-11f007a4b460/scratchpad/wt-glow/'

apply(WT + 'public_html/css/styles.css', [
(
r'''.cell .trend.tr-swap { width: 1.27cqw; }
''',
r'''.cell .trend.tr-swap { width: 1.74cqw; top: 3.46cqw; }
'''),
(
r'''  .cell .trend { width: 2.5cqw; }
''',
r'''  .cell .trend { width: 2.5cqw; }
  .cell .trend.tr-swap { width: 3.43cqw; top: 6.58cqw; }
'''),
])
```

Run: `cd $SP && python swap_css.py`
Expected: `patched …styles.css`.

- [ ] **Step 4: Тест зелёный**

Run: `cd $WT && $SP/php-gd.sh tests/tags_test.php && git diff --stat`
Expected: `0 failures`; изменены `styles.css` и `tags_test.php`, без `Bin`.

- [ ] **Step 5: Замер в браузере** — `$SP/swap_measure.mjs` (не в репозитории):

```js
import { createRequire } from 'module';
const SP = 'C:/Users/nural/AppData/Local/Temp/claude/C--Users-nural-Desktop-Nexus-Tier-List/41188f68-515d-4365-80bf-11f007a4b460/scratchpad/';
const require = createRequire(SP + 'wt-glow/tools/package.json');
const { chromium } = require('playwright');

const BASE = 'http://127.0.0.1:8880';
const item = (id, trend) => ({
    id, name: id, value: '1000', icon: 'assets/icon-sample.png', type: 'f', demand: 'green', trend,
    desc: '', descEn: '', terms: '', termsEn: '', tag: '', tagEn: '', flag: false, wip: false, glow: '',
});
const FIXTURE = {
    title: 'T', date: '17.09.2026', autoSort: false,
    filters: { configurators: true, fruits: true, perms: false, passes: true },
    ad: { text: '', image: '', link: '' },
    tiers: [{ id: 't1', label: 'MK', logo: 'assets/logo-mk.png', items: [item('sw', 'swap'), item('up', 'up'), item('dn', 'down')] }],
    _rev: 1,
};

let failed = 0;
const check = (name, ok, detail) => {
    console.log(`${ok ? 'ok  ' : 'FAIL'} - ${name}${ok ? '' : '   ' + JSON.stringify(detail)}`);
    if (!ok) failed++;
};

const browser = await chromium.launch();
for (const [name, mobile] of [['компьютер', false], ['телефон', true]]) {
    const ctx = await browser.newContext({
        viewport: mobile ? { width: 390, height: 844 } : { width: 1440, height: 1000 },
        isMobile: mobile, hasTouch: mobile,
    });
    const page = await ctx.newPage();
    page.setDefaultNavigationTimeout(45000);
    const json = d => ({ status: 200, contentType: 'application/json', body: JSON.stringify(d) });
    await page.route('**/api/state.php*', r => r.fulfill(json({ rev: 1, likes: 0 })));
    await page.route('**/api/tierlist.php*', r => r.fulfill(json({ tierlist: FIXTURE, likes: 0 })));
    await page.route('**/api/promo.php*', r => r.fulfill(json({ v: 1, rev: 0, campaigns: [] })));
    await page.goto(BASE + '/index.php', { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(() => {
        const imgs = [...document.querySelectorAll('.cell .trend')];
        return imgs.length === 3 && imgs.every(i => i.complete && i.naturalWidth);
    }, null, { timeout: 20000 });
    const m = await page.evaluate(() => {
        const box = sel => document.querySelector(sel).getBoundingClientRect();
        const cellSw = box('.cell[data-id="sw"]');
        const cellUp = box('.cell[data-id="up"]');
        const sw = box('.cell[data-id="sw"] .trend');
        const up = box('.cell[data-id="up"] .trend');
        const strip = box('.cell[data-id="sw"] .cell-strip');
        return {
            ratio: sw.width / up.width,
            swBottom: sw.bottom - cellSw.top,
            upBottom: up.bottom - cellUp.top,
            stripTop: strip.top - cellSw.top,
        };
    });
    check(`${name}: перерассмотр в 1,37 раза шире стрелки`, Math.abs(m.ratio - 37 / 27) < 0.03, m);
    check(`${name}: низ на уровне стрелки (±1px)`, Math.abs(m.swBottom - m.upBottom) <= 1, m);
    check(`${name}: на цену не наезжает`, m.swBottom <= m.stripTop + 0.5, m);
    await ctx.close();
}
await browser.close();
console.log(failed ? `\n${failed} FAILED` : '\nALL OK');
process.exit(failed ? 1 : 0);
```

Run: `cd $SP && node swap_measure.mjs`
Expected: шесть `ok`, `ALL OK`. (До правки CSS тот же скрипт даёт `FAIL` отношения на обоих экранах — на телефоне около 0,51.)

- [ ] **Step 6: Глазами** — снимки живого тирлиста (прокси данных с `https://maknemy.com`, как в `$SP/swap_sizes.mjs`) на локальном сервере с новым CSS: компьютер и телефон, «Перерассмотр» рядом со стрелками. Должно совпасть с вариантом A утверждённого макета.

- [ ] **Step 7: Коммит**

```bash
cd $WT && git add public_html/css/styles.css tests/tags_test.php && git commit -m "fix(tierlist): значок «Перерассмотр цены» крупнее, как в легенде

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 2: Кэш, полный прогон, PR

**Files:**
- Modify: `public_html/index.php`

- [ ] **Step 1: Версия** — `$SP/swap_cache.py`:

```python
from patch import apply

apply('C:/Users/nural/AppData/Local/Temp/claude/C--Users-nural-Desktop-Nexus-Tier-List/41188f68-515d-4365-80bf-11f007a4b460/scratchpad/wt-glow/public_html/index.php', [
    ('css/styles.css?v=58"', 'css/styles.css?v=59"'),
])
```

Run: `cd $SP && python swap_cache.py && cd $WT && git grep -n "styles.css?v=" -- public_html`
Expected: `index.php:…:css/styles.css?v=59`.

- [ ] **Step 2: Полный прогон**

```bash
cd $WT && for t in tests/*_test.php tests/harness_selfcheck.php; do $SP/php-gd.sh "$t" >/dev/null 2>&1 || echo "FAIL $t"; done
cd $WT && export PHP=C:/xampp/php/php.exe && for t in tests/*_test.mjs; do node --test "$t" >/dev/null 2>&1 || echo "FAIL $t"; done
cd $WT && node tools/check-item-glow-ui.mjs | tail -1
cd $SP && node swap_measure.mjs | tail -1
```

Expected: ни одной строки `FAIL`; дважды `ALL OK`.

- [ ] **Step 3: Коммит**

```bash
cd $WT && git add public_html/index.php && git commit -m "chore(cache): версия styles.css под размер значка перерассмотра

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

- [ ] **Step 4: Push и PR** — `git fetch maknemy`; если `maknemy/master` ушёл от `9372059` — `git merge maknemy/master`, заново шаг 2 и сверка `?v=` (номер обязан остаться выше боевого). Затем:

```bash
cd $WT && git push -u maknemy fix/trend-swap-size
gh pr create --repo kannurali/MaknemyTierlist --base master --head fix/trend-swap-size --title "fix(tierlist): значок «Перерассмотр цены» крупнее, как в легенде" --body-file $SP/pr-body-swap.md
```

`$SP/pr-body-swap.md` — причина (компьютер: одна ширина со стрелками; телефон: специфичность), что изменилось (две строки, расчёт), проверка (тесты, замер, скриншоты); последняя строка `🤖 Generated with [Claude Code](https://claude.com/claude-code)`.
