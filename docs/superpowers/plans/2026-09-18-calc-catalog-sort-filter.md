# Каталог калькулятора: порядок по значку и фильтр — план

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** каталог калькулятора идёт по значку (FR, CS, CM, MS, CR, PM, GP, VH), внутри значка по цене, и фильтруется кнопками тирлиста.

**Architecture:** порядок и фильтр — чистые функции в `public_html/js/calc.js` с node-тестами. `calculator-page.js` сортирует каталог при каждой загрузке тирлиста и строит сетку через `CALC.filterCatalog`. Кнопки — разметка в `calculator.php`, вид — `calculator.css`. Проверка в браузере — `tools/check-calc-catalog-ui.mjs` по образцу `tools/check-item-glow-ui.mjs`.

**Tech Stack:** plain JS без сборки (ES5 в `calc.js`, ES2017 в `calculator-page.js`), PHP-страница, `node --test`, PHP-раннер `tests/lib.php`, Playwright 1.62.1.

**Спека:** `docs/superpowers/specs/2026-09-18-calc-catalog-sort-filter-design.md`.

## Global Constraints

- Рабочее дерево `$WT` = `C:/Users/nural/AppData/Local/Temp/claude/C--Users-nural-Desktop-Nexus-Tier-List/ab50761e-8b4d-4fd0-8f72-3cbb372ba095/scratchpad/wt-catalog`, ветка `feat/calc-catalog-sort-filter`. Главное дерево `C:\Users\nural\Desktop\Nexus Tier List` не трогать: в нём параллельно работает владелец. Из него только читать (Playwright в Task 4).
- В `public_html/` никаких комментариев: ни в js, ни в css, ни в разметке. Пояснения — в сообщение коммита.
- Меняешь js/css — в том же коммите поднимаешь его `?v=` в `public_html/calculator.php`: `calc.js` 8 → 9, `calculator-page.js` 18 → 19, `calculator.css` 23 → 24. Других страниц эти файлы не касаются.
- `public_html/js/i18n.js` не трогать. Подписи — готовые ключи `filters.fruits`, `filters.configurators`, `filters.perms`, `filters.passes`, `filters.all`, их `…Title` и `news.filterGroupLabel`.
- Порядок значков дословно `["fr", "cs", "cm", "ms", "cr", "pm", "gp", "vh"]`, группы дословно `["fruits", "configurators", "perms", "passes"]`.
- DOM в `calculator-page.js` — только `textContent` и `setAttribute`, `innerHTML` запрещён (тест). `calc.js` не трогает DOM: ни `document.`, ни `window.` (тест).
- PHP: `/c/xampp/php/php.exe`. Node: `node --test <файл>`.
- Рабочие файлы с CRLF, индекс хранит LF. Task 1, шаг 1 переводит правимые файлы в LF — без этого Edit не находит строки. После каждой правки `git diff --stat` не должен показывать `Bin` (так выглядит NUL-байт, который Edit однажды уже вписал в этот репозиторий).
- Сообщения коммитов на русском, в стиле истории (`feat(calculator): …`), последняя строка `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`.

---

### Task 1: чистые функции каталога в calc.js

**Files:**
- Modify: `public_html/js/calc.js` — новый блок сразу после функции `badgeCodeFor`, новые поля в объекте `api`
- Modify: `public_html/calculator.php` — строка `<script src="js/calc.js?v=8" …>`
- Test: `tests/calc_test.mjs` — новый раздел в конце файла

**Interfaces:**
- Consumes: `badgeCodeFor(type)` и `itemValue(item)` из того же `calc.js`.
- Produces (в `CALC` и `module.exports`):
  - `CATALOG_ORDER: string[]` — `["fr", "cs", "cm", "ms", "cr", "pm", "gp", "vh"]`
  - `FILTER_GROUPS: string[]` — `["fruits", "configurators", "perms", "passes"]`
  - `sortCatalog(items: Item[] | null): Item[]` — новый массив, вход не меняется
  - `filterGroupOf(type: any): "fruits" | "configurators" | "perms" | "passes"`
  - `allFiltersOn(): { fruits: true, configurators: true, perms: true, passes: true }` — новый объект на каждый вызов
  - `toggleFilter(filters: Filters, key: string): Filters` — новый объект; `key` — группа или `"all"`
  - `filterCatalog(items: Item[] | null, filters?: Filters, query?: string): Item[]` — без `filters` показывает всё

- [ ] **Step 1: перевести правимые файлы в LF**

```bash
cd "$WT"
FILES="public_html/calculator.php public_html/js/calc.js public_html/js/calculator-page.js public_html/css/calculator.css tests/calc_test.mjs tests/calculator_page_test.php"
rm $FILES && git -c core.autocrlf=false checkout -- $FILES
git ls-files --eol $FILES
git status --short
```

Expected: у всех шести `i/lf w/lf`; `git status --short` пустой.

- [ ] **Step 2: написать падающие тесты**

Дописать в конец `tests/calc_test.mjs` (`readFileSync` там уже импортирован):

```js
// --------------------------------------------------------------------------
//  Каталог: порядок по значку и фильтр по группам тирлиста
//  (docs/superpowers/specs/2026-09-18-calc-catalog-sort-filter-design.md)
// --------------------------------------------------------------------------

const { sortCatalog, filterGroupOf, allFiltersOn, toggleFilter, filterCatalog, CATALOG_ORDER, FILTER_GROUPS } = CALC;

function typed(id, type, value) {
    return { id, name: id, value: String(value), icon: '/images/x.webp', type, demand: 'green' };
}

test('sortCatalog ставит значки в порядке FR, CS, CM, MS, CR, PM, GP, VH', () => {
    const shuffled = [
        typed('vh', 'vh', 100), typed('pm', 'p', 900), typed('cr', 'cr', 50000),
        typed('gp', 'gp', 5400), typed('ms', 'ms', 19250), typed('fr', 'f', 1),
        typed('cm', 'cm', 1600), typed('cs', 's', 20500),
    ];
    assert.deepEqual(sortCatalog(shuffled).map(it => it.id), ['fr', 'cs', 'cm', 'ms', 'cr', 'pm', 'gp', 'vh']);
    assert.deepEqual(CATALOG_ORDER, ['fr', 'cs', 'cm', 'ms', 'cr', 'pm', 'gp', 'vh']);
});

test('внутри значка дорогие выше', () => {
    const items = [typed('dark', 'f', '0.3'), typed('meme', 'f', 9000), typed('kitsune', 'f', 800)];
    assert.deepEqual(sortCatalog(items).map(it => it.id), ['meme', 'kitsune', 'dark']);
});

test('при равной цене остаётся порядок тирлиста', () => {
    const items = [typed('tiger', 'p', 7800), typed('yeti', 'p', 7800), typed('gas', 'p', 6250)];
    assert.deepEqual(sortCatalog(items).map(it => it.id), ['tiger', 'yeti', 'gas']);
    assert.deepEqual(sortCatalog([items[1], items[0], items[2]]).map(it => it.id), ['yeti', 'tiger', 'gas']);
});

// «0,4» у Eagle Fruit на бою: parseValue запятую намеренно не читает, так
// что предмет стоит 0 — и уходит в конец фруктов, а не всего каталога.
test('нечитаемая цена уходит в конец своего значка, а не всего каталога', () => {
    const items = [typed('eagle', 'f', '0,4'), typed('dark', 'f', '0.3'), typed('perm', 'p', 1)];
    assert.deepEqual(sortCatalog(items).map(it => it.id), ['dark', 'eagle', 'perm']);
});

test('sortCatalog не меняет входной массив и не падает на пустом', () => {
    const items = [typed('b', 'p', 1), typed('a', 'f', 1)];
    const sorted = sortCatalog(items);
    assert.deepEqual(items.map(it => it.id), ['b', 'a']);
    assert.deepEqual(sorted.map(it => it.id), ['a', 'b']);
    assert.notEqual(sorted, items);
    assert.deepEqual(sortCatalog([]), []);
    assert.deepEqual(sortCatalog(null), []);
    assert.deepEqual(sortCatalog(undefined), []);
});

test('неизвестный тип встаёт к фруктам — туда же, куда его значок', () => {
    const items = [typed('cs', 's', 5), typed('odd', 'nope', 1)];
    assert.deepEqual(sortCatalog(items).map(it => it.id), ['odd', 'cs']);
    assert.equal(filterGroupOf('nope'), 'fruits');
});

// Группы обязаны совпадать с тирлистом, иначе «Пермы» в каталоге и в
// тирлисте покажут разное. groupOf берётся из самого app.js: поменяют
// группы тирлиста — этот тест упадёт.
test('filterGroupOf делит типы из боевых данных так же, как groupOf тирлиста', () => {
    const app = readFileSync(new URL('../public_html/js/app.js', import.meta.url), 'utf8');
    const src = app.match(/function groupOf\(type\) \{[\s\S]*?\n {2}\}/);
    assert.ok(src, 'groupOf не найден в app.js — поправить тест вслед за тирлистом');
    const tierlistGroupOf = new Function('return ' + src[0])();
    for (const type of ['f', '', 'p', 's', 'm', 'cs', 'cm', 'ms', 'cr', 'gp', 'vh', 'v']) {
        assert.equal(filterGroupOf(type), tierlistGroupOf(type), `тип "${type}"`);
    }
    assert.deepEqual(FILTER_GROUPS, ['fruits', 'configurators', 'perms', 'passes']);
});

test('toggleFilter выключает и включает группу, не трогая остальные', () => {
    const off = toggleFilter(allFiltersOn(), 'perms');
    assert.deepEqual(off, { fruits: true, configurators: true, perms: false, passes: true });
    assert.deepEqual(toggleFilter(off, 'perms'), allFiltersOn());
});

test('последнюю включённую группу выключить нельзя — как в тирлисте', () => {
    const only = { fruits: true, configurators: false, perms: false, passes: false };
    assert.deepEqual(toggleFilter(only, 'fruits'), only);
});

test('«Все» включает все группы', () => {
    const only = { fruits: false, configurators: false, perms: true, passes: false };
    assert.deepEqual(toggleFilter(only, 'all'), allFiltersOn());
    assert.deepEqual(allFiltersOn(), { fruits: true, configurators: true, perms: true, passes: true });
});

test('toggleFilter возвращает новый объект и не меняет старый', () => {
    const start = allFiltersOn();
    const next = toggleFilter(start, 'fruits');
    assert.notEqual(next, start);
    assert.deepEqual(start, allFiltersOn());
    assert.notEqual(allFiltersOn(), allFiltersOn(), 'каждый вызов — свой объект');
});

test('filterCatalog оставляет только включённые группы', () => {
    const items = [typed('meme', 'f', 9000), typed('slayer', 'cs', 20500), typed('pkitsune', 'p', 12500), typed('notifier', 'gp', 5400)];
    const noPerms = toggleFilter(allFiltersOn(), 'perms');
    assert.deepEqual(filterCatalog(items, noPerms, '').map(it => it.id), ['meme', 'slayer', 'notifier']);
    assert.deepEqual(filterCatalog(items, allFiltersOn(), '').map(it => it.id), ['meme', 'slayer', 'pkitsune', 'notifier']);
});

test('поиск работает внутри включённых групп, без учёта регистра и пробелов по краям', () => {
    const items = [
        { ...typed('a', 'f', 1), name: 'Dragon (West) Fruit' },
        { ...typed('b', 'p', 1), name: 'Permanent Dragon (West + East)' },
        { ...typed('c', 'f', 1), name: 'Magnet Fruit' },
    ];
    const fruitsOnly = { fruits: true, configurators: false, perms: false, passes: false };
    assert.deepEqual(filterCatalog(items, fruitsOnly, '  DRAGON ').map(it => it.id), ['a']);
    assert.deepEqual(filterCatalog(items, allFiltersOn(), 'dragon').map(it => it.id), ['a', 'b']);
    assert.deepEqual(filterCatalog(items, fruitsOnly, 'kitsune'), []);
});

test('filterCatalog сохраняет порядок и не падает на пустом', () => {
    const items = sortCatalog([typed('p1', 'p', 5), typed('f1', 'f', 1), typed('f2', 'f', 2)]);
    assert.deepEqual(filterCatalog(items, allFiltersOn(), '').map(it => it.id), ['f2', 'f1', 'p1']);
    assert.deepEqual(filterCatalog(null, allFiltersOn(), 'x'), []);
    assert.deepEqual(filterCatalog(items, undefined, '').map(it => it.id), ['f2', 'f1', 'p1'], 'без фильтра — всё');
});
```

- [ ] **Step 3: убедиться, что тесты падают**

Run: `cd "$WT" && node --test tests/calc_test.mjs`
Expected: FAIL — новые тесты с `TypeError: sortCatalog is not a function` (и так же для остальных функций), старые проходят.

- [ ] **Step 4: реализация в calc.js**

В `public_html/js/calc.js` сразу после закрывающей скобки функции `badgeCodeFor` (строка `return Object.prototype.hasOwnProperty.call(RAW_TYPE_TO_BADGE, t) ? RAW_TYPE_TO_BADGE[t] : "fr";` и следующая `  }`) вставить:

```js

  var CATALOG_ORDER = ["fr", "cs", "cm", "ms", "cr", "pm", "gp", "vh"];

  function sortCatalog(items) {
    return (items || [])
      .map(function (it, i) {
        return { it: it, i: i, rank: CATALOG_ORDER.indexOf(badgeCodeFor(it && it.type)), value: itemValue(it) };
      })
      .sort(function (a, b) {
        return (a.rank - b.rank) || (b.value - a.value) || (a.i - b.i);
      })
      .map(function (row) { return row.it; });
  }

  var FILTER_GROUPS = ["fruits", "configurators", "perms", "passes"];
  var BADGE_TO_GROUP = {
    fr: "fruits",
    cs: "configurators", cm: "configurators", ms: "configurators", cr: "configurators",
    pm: "perms",
    gp: "passes", vh: "passes"
  };

  function filterGroupOf(type) {
    return BADGE_TO_GROUP[badgeCodeFor(type)];
  }

  function allFiltersOn() {
    var out = {};
    FILTER_GROUPS.forEach(function (g) { out[g] = true; });
    return out;
  }

  function toggleFilter(filters, key) {
    if (key === "all") { return allFiltersOn(); }
    var next = {};
    FILTER_GROUPS.forEach(function (g) { next[g] = !!(filters && filters[g]); });
    if (FILTER_GROUPS.indexOf(key) < 0) { return next; }
    next[key] = !next[key];
    if (!FILTER_GROUPS.some(function (g) { return next[g]; })) { next[key] = true; }
    return next;
  }

  function filterCatalog(items, filters, query) {
    var on = filters || allFiltersOn();
    var q = String(query || "").toLowerCase().trim();
    return (items || []).filter(function (it) {
      if (!on[filterGroupOf(it && it.type)]) { return false; }
      return !q || String((it && it.name) || "").toLowerCase().indexOf(q) >= 0;
    });
  }
```

В объект `var api = { … }` после строки `    badgeCodeFor: badgeCodeFor,` добавить:

```js
    CATALOG_ORDER: CATALOG_ORDER,
    FILTER_GROUPS: FILTER_GROUPS,
    sortCatalog: sortCatalog,
    filterGroupOf: filterGroupOf,
    allFiltersOn: allFiltersOn,
    toggleFilter: toggleFilter,
    filterCatalog: filterCatalog,
```

- [ ] **Step 5: тесты проходят**

Run: `cd "$WT" && node --test tests/calc_test.mjs`
Expected: PASS, `# fail 0`.

- [ ] **Step 6: поднять версию calc.js**

В `public_html/calculator.php`: `<script src="js/calc.js?v=8" fetchpriority="high"></script>` → `<script src="js/calc.js?v=9" fetchpriority="high"></script>`.

- [ ] **Step 7: PHP-тесты страницы и кеша**

Run: `cd "$WT" && /c/xampp/php/php.exe tests/calculator_page_test.php && /c/xampp/php/php.exe tests/static_cache_test.php`
Expected: оба без FAIL (в том числе «calc.js не трогает DOM и экспортируется как CALC»).

- [ ] **Step 8: коммит**

```bash
cd "$WT"
git diff --stat   # ни одной строки с Bin
git add public_html/js/calc.js public_html/calculator.php tests/calc_test.mjs
git commit -F - <<'EOF'
feat(calculator): порядок каталога по значку и группы фильтра в calc.js

sortCatalog ставит значки в порядке FR, CS, CM, MS, CR, PM, GP, VH,
внутри значка по цене (itemValue), при равной цене — порядок тирлиста.
filterGroupOf, toggleFilter и filterCatalog повторяют фильтр тирлиста;
тест сверяет группы с groupOf из app.js. calc.js ?v=8 -> 9.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

### Task 2: кнопки фильтра в каталоге и логика страницы

**Files:**
- Modify: `public_html/calculator.php` — блок `<div class="tc-cat-sub">` и строка `js/calculator-page.js?v=18`
- Modify: `public_html/js/calculator-page.js` — `norm`, `renderCatalogGrid`, `wireCatalog`, `applyTierlist`, запуск внизу файла
- Test: `tests/calculator_page_test.php` — три теста перед `run_tests();`

**Interfaces:**
- Consumes (Task 1): `CALC.sortCatalog`, `CALC.filterCatalog`, `CALC.toggleFilter`, `CALC.allFiltersOn`, `CALC.FILTER_GROUPS`.
- Produces: разметку `#tcCatalogFilters` с кнопками `.chip[data-f]` (`fruits`, `configurators`, `perms`, `passes`, `all`) и состоянием в `aria-pressed` — на неё опираются Task 3 (CSS) и Task 4 (проверка в браузере).

- [ ] **Step 1: написать падающие тесты**

В `tests/calculator_page_test.php` перед последней строкой `run_tests();` вставить:

```php
// --------------------------------------------------------------------------
//  Каталог: порядок по значку и фильтр тирлиста
//  (docs/superpowers/specs/2026-09-18-calc-catalog-sort-filter-design.md)
// --------------------------------------------------------------------------

// Кнопки те же, что у тирлиста (#filters в index.php), но идут в порядке
// карточек: фрукты первыми. При загрузке включено всё — без пермов в
// калькуляторе не собрать сделку.
test('в каталоге фильтр тирлиста: пять кнопок в порядке карточек', function () use ($PUB) {
    $calc = calc_read($PUB . '/calculator.php');
    assert_true((bool)preg_match('/<div class="tc-cat-filters" id="tcCatalogFilters" role="group"[^>]*>(.*?)<\/div>/s', $calc, $m),
        'в каталоге должна быть группа кнопок фильтра');
    preg_match_all('/data-f="([a-z]+)"/', $m[1], $keys);
    assert_eq(['fruits', 'configurators', 'perms', 'passes', 'all'], $keys[1], 'кнопки и их порядок');
    assert_eq(5, substr_count($m[1], 'aria-pressed="true"'), 'при загрузке включено всё');
    $filters = strpos($calc, 'id="tcCatalogFilters"');
    assert_true(strpos($calc, 'id="tcCatalogTitle"') < $filters && $filters < strpos($calc, 'id="tcCatalogClose"'),
        'фильтр стоит в строке «Каталог», перед крестиком');
});

test('подписи фильтра каталога есть в словаре на обоих языках', function () use ($PUB) {
    $calc = calc_read($PUB . '/calculator.php');
    $i18n = calc_read($PUB . '/js/i18n.js');
    assert_true((bool)preg_match('/<div class="tc-cat-filters".*?<\/div>/s', $calc, $m), 'группа кнопок фильтра');
    preg_match_all('/data-i18n(?:-title|-label)?="([a-zA-Z.]+)"/', $m[0], $keys);
    assert_eq(11, count(array_unique($keys[1])), 'подпись и подсказка у каждой из пяти кнопок и название группы');
    foreach (array_unique($keys[1]) as $key) {
        assert_eq(2, substr_count($i18n, '"' . $key . '"'), "ключ $key должен быть и в ru, и в en");
    }
});

test('каталог сортируется по значку и фильтруется функциями calc.js', function () use ($PUB) {
    $js = calc_read($PUB . '/js/calculator-page.js');
    assert_true(strpos($js, 'CALC.sortCatalog(CALC.flattenTierlist(doc))') !== false,
        'порядок по значку при каждой загрузке тирлиста');
    assert_true(strpos($js, 'CALC.filterCatalog(catalog, catalogFilters, query)') !== false,
        'сетка — через фильтр и поиск из calc.js');
    assert_true(strpos($js, 'CALC.toggleFilter(catalogFilters, chip.dataset.f)') !== false,
        'кнопки переключают группы по правилам тирлиста');
});
```

- [ ] **Step 2: убедиться, что тесты падают**

Run: `cd "$WT" && /c/xampp/php/php.exe tests/calculator_page_test.php`
Expected: FAIL у трёх новых тестов («в каталоге должна быть группа кнопок фильтра», «группа кнопок фильтра», «порядок по значку при каждой загрузке тирлиста»), остальные ok.

- [ ] **Step 3: разметка**

В `public_html/calculator.php` заменить

```html
        <div class="tc-cat-sub">
          <span class="tc-pill" id="tcCatalogTitle" data-i18n="calc.catalogPill">Каталог</span>
          <button type="button" class="tc-cat-close" id="tcCatalogClose" data-i18n-label="calc.catalogClose" aria-label="Закрыть каталог">✕</button>
        </div>
```

на

```html
        <div class="tc-cat-sub">
          <span class="tc-pill" id="tcCatalogTitle" data-i18n="calc.catalogPill">Каталог</span>
          <div class="tc-cat-filters" id="tcCatalogFilters" role="group" data-i18n-label="news.filterGroupLabel" aria-label="Фильтр по категориям">
            <button type="button" class="chip" data-f="fruits" data-i18n="filters.fruits" data-i18n-title="filters.fruitsTitle" title="Обычные фрукты" aria-pressed="true">Фрукты</button>
            <button type="button" class="chip" data-f="configurators" data-i18n="filters.configurators" data-i18n-title="filters.configuratorsTitle" title="Скины, мутации, конфигурации и хроматики" aria-pressed="true">Конфигураторы</button>
            <button type="button" class="chip" data-f="perms" data-i18n="filters.perms" data-i18n-title="filters.permsTitle" title="Перманентные фрукты" aria-pressed="true">Пермы</button>
            <button type="button" class="chip" data-f="passes" data-i18n="filters.passes" data-i18n-title="filters.passesTitle" title="Геймпассы и воучеры" aria-pressed="true">Пассы</button>
            <button type="button" class="chip all" data-f="all" data-i18n="filters.all" data-i18n-title="filters.allTitle" title="Показать всё" aria-pressed="true">Все</button>
          </div>
          <button type="button" class="tc-cat-close" id="tcCatalogClose" data-i18n-label="calc.catalogClose" aria-label="Закрыть каталог">✕</button>
        </div>
```

- [ ] **Step 4: состояние фильтра вместо norm**

В `public_html/js/calculator-page.js` заменить

```js
  const catalogState = { open: false, side: null, triggerEl: null, slotIndex: -1 };

  function norm(s) { return String(s || "").toLowerCase(); }
```

на

```js
  const catalogState = { open: false, side: null, triggerEl: null, slotIndex: -1 };

  let catalogFilters = CALC.allFiltersOn();

  function renderCatalogFilters() {
    const allOn = CALC.FILTER_GROUPS.every(key => catalogFilters[key]);
    document.querySelectorAll("#tcCatalogFilters .chip").forEach(chip => {
      const key = chip.dataset.f;
      chip.setAttribute("aria-pressed", String(key === "all" ? allOn : !!catalogFilters[key]));
    });
  }
```

(`norm` больше нигде не используется — проверить: `grep -n "norm(" public_html/js/calculator-page.js` после шага 5 ничего не находит.)

- [ ] **Step 5: сетка через filterCatalog**

Заменить

```js
  function renderCatalogGrid(query) {
    const grid = $("#tcCatalogGrid");
    const q = norm(query).trim();
    grid.textContent = "";
    const matches = q ? catalog.filter(it => norm(it.name).includes(q)) : catalog;
```

на

```js
  function renderCatalogGrid(query) {
    const grid = $("#tcCatalogGrid");
    grid.textContent = "";
    const matches = CALC.filterCatalog(catalog, catalogFilters, query);
```

- [ ] **Step 6: обработчик кнопок**

Заменить

```js
  function wireCatalog() {
    $("#tcCatalogSearch").addEventListener("input", e => queueCatalogRender(e.target.value));
```

на

```js
  function wireCatalog() {
    $("#tcCatalogSearch").addEventListener("input", e => queueCatalogRender(e.target.value));
    $("#tcCatalogFilters").addEventListener("click", e => {
      const chip = e.target.closest(".chip");
      if (!chip) return;
      const next = CALC.toggleFilter(catalogFilters, chip.dataset.f);
      if (CALC.FILTER_GROUPS.every(key => next[key] === catalogFilters[key])) return;
      catalogFilters = next;
      renderCatalogFilters();
      cancelQueuedRender();
      renderCatalogGrid($("#tcCatalogSearch").value);
      $("#tcCatalogGrid").scrollTop = 0;
    });
```

- [ ] **Step 7: сортировка при загрузке и начальное состояние кнопок**

Заменить `    catalog = CALC.flattenTierlist(doc);` на `    catalog = CALC.sortCatalog(CALC.flattenTierlist(doc));`.

Внизу файла заменить

```js
  wireCatalog();
  wireActions();
```

на

```js
  wireCatalog();
  renderCatalogFilters();
  wireActions();
```

- [ ] **Step 8: поднять версию calculator-page.js**

В `public_html/calculator.php`: `<script src="js/calculator-page.js?v=18" fetchpriority="high"></script>` → `<script src="js/calculator-page.js?v=19" fetchpriority="high"></script>`.

- [ ] **Step 9: тесты проходят**

Run:
```bash
cd "$WT"
grep -n "norm(" public_html/js/calculator-page.js; echo "grep exit $?"
/c/xampp/php/php.exe tests/calculator_page_test.php && /c/xampp/php/php.exe tests/static_cache_test.php && node --test tests/calc_test.mjs
```
Expected: `grep exit 1` (ничего не найдено); все PHP-тесты ok; node `# fail 0`.

- [ ] **Step 10: коммит**

```bash
cd "$WT"
git diff --stat   # ни одной строки с Bin
git add public_html/calculator.php public_html/js/calculator-page.js tests/calculator_page_test.php
git commit -F - <<'EOF'
feat(calculator): фильтр тирлиста в каталоге, порядок по значку

В строке «Каталог» кнопки Фрукты, Конфигураторы, Пермы, Пассы, Все —
те же группы и правила, что в тирлисте; при загрузке включено всё,
выбор живёт до перезагрузки страницы. Поиск идёт внутри включённых
групп, смена фильтра прокручивает сетку в начало. Каталог сортируется
по значку при каждой загрузке тирлиста. calculator-page.js ?v=18 -> 19.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

### Task 3: вид кнопок фильтра

**Files:**
- Modify: `public_html/css/calculator.css` — после `.tc-cat-close:focus-visible`, блоки `@media (hover: hover)`, `@media (prefers-reduced-motion: reduce)`, `@media (max-width: 900px)`, `@media (min-width: 560px) and (max-width: 900px)`
- Modify: `public_html/calculator.php` — строка `css/calculator.css?v=23`
- Test: `tests/calculator_page_test.php` — список в тесте про `focus-visible` и новый тест

**Interfaces:**
- Consumes (Task 2): `.tc-cat-sub` > `.tc-cat-filters#tcCatalogFilters` > `.chip[data-f][aria-pressed]`.
- Produces: раскладку, которую проверяет Task 4 — компьютер: кнопки в одной строке с «Каталог» и ✕; до 900px: кнопки отдельной строкой ниже, на 360px и шире все пять видны без прокрутки.

Размеры сняты замером подписей шрифтом страницы (Bebas Neue → Oswald): сумма ширин пяти русских подписей 290px при 17px, 256px при 15px, 222px при 13px. Компьютер: место под кнопки 576px, нужно 290 + 5·32 + 4·12 = 498. Планшет 560px: 488px, нужно 256 + 5·24 + 4·8 = 408. Телефон: 350px на 390, 320px на 360, нужно 222 + 5·14 + 4·6 = 316.

- [ ] **Step 1: написать падающие тесты**

В `tests/calculator_page_test.php` в тесте `'интерактивные элементы калькулятора получают видимый focus-visible'` заменить строку

```php
    foreach (['.tc-search-input:focus-visible', '.tc-btn:focus-visible', '.tc-slot:focus-visible'] as $sel) {
```

на

```php
    foreach (['.tc-search-input:focus-visible', '.tc-btn:focus-visible', '.tc-slot:focus-visible',
              '.tc-cat-filters .chip:focus-visible'] as $sel) {
```

и перед `run_tests();` вставить:

```php
// «Как в тирлисте» — и на вид: включённая кнопка каталога залита тем же
// градиентом и обведена тем же кольцом, что кнопка фильтра тирлиста.
test('включённая кнопка фильтра каталога выглядит как в тирлисте', function () use ($PUB) {
    $calc = calc_read($PUB . '/css/calculator.css');
    $page = calc_read($PUB . '/css/design-page.css');
    $rule = '/%s \.chip\[aria-pressed="true"\] \{([^}]*)\}/';
    assert_true((bool)preg_match(sprintf($rule, '\.toolbar'), $page, $a), 'правило включённой кнопки тирлиста');
    assert_true((bool)preg_match(sprintf($rule, '\.tc-cat-filters'), $calc, $b), 'правило включённой кнопки каталога');
    foreach (['background', 'box-shadow'] as $prop) {
        preg_match('/' . $prop . ': ([^;]+);/', $a[1], $x);
        preg_match('/' . $prop . ': ([^;]+);/', $b[1], $y);
        assert_true(!empty($x[1]) && !empty($y[1]), "$prop задан в обоих правилах");
        assert_eq($x[1], $y[1], "$prop включённой кнопки тот же, что в тирлисте");
    }
});
```

- [ ] **Step 2: убедиться, что тесты падают**

Run: `cd "$WT" && /c/xampp/php/php.exe tests/calculator_page_test.php`
Expected: FAIL — «нет правила .tc-cat-filters .chip:focus-visible» и «правило включённой кнопки каталога».

- [ ] **Step 3: основные правила**

В `public_html/css/calculator.css` после строки

```css
.tc-cat-close:focus-visible { outline: 2px solid var(--cyan); outline-offset: 2px; }
```

вставить

```css

.tc-cat-filters {
  flex: 1 1 auto;
  min-width: 0;
  display: flex;
  align-items: center;
  gap: 12px;
  margin: -5px 0;
  padding: 5px;
  overflow-x: auto;
  overscroll-behavior-x: contain;
  scrollbar-width: none;
}
.tc-cat-filters::-webkit-scrollbar { display: none; }
.tc-cat-filters .chip {
  flex: 0 0 auto;
  padding: 10px 16px;
  border: 0;
  border-radius: 10px;
  background: none;
  color: #fff;
  font-family: "Bebas Neue", "Oswald", "Arial Narrow", sans-serif;
  font-size: 17px;
  font-weight: 400;
  line-height: 1;
  text-transform: uppercase;
  transition: background .15s, box-shadow .15s;
}
.tc-cat-filters .chip[aria-pressed="true"] {
  background: linear-gradient(255deg, #61b5e9 0%, #2d4aed 100%);
  box-shadow: 0 0 0 5px rgba(136, 176, 255, 0.32);
}
.tc-cat-filters .chip:focus-visible { outline: 2px solid var(--cyan); outline-offset: 2px; }
```

- [ ] **Step 4: hover и reduced-motion**

В блоке `@media (hover: hover)` после строки

```css
  .tc-cat-card:not(.is-full):hover .tc-cat-inner { box-shadow: inset 0 0 0 2px var(--cyan); }
```

добавить

```css
  .tc-cat-filters .chip:not([aria-pressed="true"]):hover { background: rgba(255, 255, 255, .08); }
```

В блоке `@media (prefers-reduced-motion: reduce)` после строки

```css
  .tc-page * { transition: none !important; }
```

добавить

```css
  .tc-cat-filters .chip { transition: none; }
```

- [ ] **Step 5: телефон и планшет**

В блоке `@media (max-width: 900px)` после строки

```css
  .tc-cat-close { width: 30px; height: 30px; font-size: 13px; }
```

добавить

```css
  .tc-cat-sub { flex-wrap: wrap; row-gap: 10px; }
  .tc-cat-filters { order: 1; flex: 1 1 100%; margin: -5px; gap: 6px; }
  .tc-cat-filters .chip { padding: 7px; font-size: 13px; }
  .tc-cat-filters .chip[aria-pressed="true"] { box-shadow: 0 0 0 3px rgba(136, 176, 255, 0.32); }
```

В блоке `@media (min-width: 560px) and (max-width: 900px)` после строки

```css
  .tc-cat-close { width: 35px; height: 35px; font-size: 15px; }
```

добавить

```css
  .tc-cat-filters { gap: 8px; }
  .tc-cat-filters .chip { padding: 8px 12px; font-size: 15px; }
```

- [ ] **Step 6: поднять версию calculator.css**

В `public_html/calculator.php`: `<link rel="stylesheet" href="css/calculator.css?v=23" />` → `<link rel="stylesheet" href="css/calculator.css?v=24" />`.

- [ ] **Step 7: тесты проходят**

Run: `cd "$WT" && /c/xampp/php/php.exe tests/calculator_page_test.php && /c/xampp/php/php.exe tests/static_cache_test.php`
Expected: все ok.

- [ ] **Step 8: коммит**

```bash
cd "$WT"
git diff --stat   # ни одной строки с Bin
git add public_html/css/calculator.css public_html/calculator.php tests/calculator_page_test.php
git commit -F - <<'EOF'
feat(calculator): кнопки фильтра каталога в виде тирлиста

Включённая кнопка — тот же градиент и кольцо, что у фильтра тирлиста.
На компьютере кнопки стоят в строке «Каталог» между плашкой и
крестиком, до 900px — отдельной строкой ниже; размеры подобраны
замером, на 360px и шире все пять влезают без прокрутки.
calculator.css ?v=23 -> 24.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

### Task 4: проверка в браузере

**Files:**
- Create: `tools/check-calc-catalog-ui.mjs`
- Local only (в `.gitignore`): `config.php` в корне `$WT`, `tools/node_modules/`, `tools/out/`

**Interfaces:**
- Consumes: всё из Task 1–3 — `#tcCatalogFilters .chip[data-f][aria-pressed]`, `#tcCatalogGrid .tc-cat-name`, `#tcCatalogSearch`, `#tcCatalogTitle`, `#tcCatalogClose`, `#tcState`, `#langSwitch [data-lang]`.
- Produces: `node tools/check-calc-catalog-ui.mjs <BASE>` печатает `ok`/`FAIL` построчно и выходит с кодом 1 при любом FAIL; скриншоты в `tools/out/`.

- [ ] **Step 1: окружение**

Файл `$WT/config.php` (Write tool):

```php
<?php
return [
    'dsn' => 'sqlite::memory:',
    'db_user' => null,
    'db_pass' => null,
    'admin_hash' => '',
    'images_dir' => __DIR__ . '/public_html/images',
];
```

Playwright скопировать из главного дерева (junction нельзя: удаление worktree пройдёт по ссылке), свой браузер Playwright на машине не скачан — берётся установленный Chrome через `PW_CHANNEL=chrome`:

```bash
cp -r "/c/Users/nural/Desktop/Nexus Tier List/tools/node_modules" "$WT/tools/node_modules"
cd "$WT" && git status --short   # пусто: всё это в .gitignore
```

- [ ] **Step 2: скрипт проверки**

Создать `tools/check-calc-catalog-ui.mjs`:

```js
// Browser check for the calculator catalog: badge order and the tier-list
// filter (docs/superpowers/specs/2026-09-18-calc-catalog-sort-filter-design.md).
//
//   node tools/check-calc-catalog-ui.mjs [http://127.0.0.1:8880]
//
// tests/calc_test.mjs covers the pure functions and
// tests/calculator_page_test.php pins the markup and CSS as text. This drives
// the real page: card order, every chip, the last-group rule, filter plus
// search, the choice surviving a reopen, English labels, and the chip row on
// a computer and on two phone widths. Screenshots go to tools/out/.
//
// The tier list comes from a fixture: api/tierlist.php, api/state.php and
// api/promo.php are answered here, so the check needs no database content.
//
// Needs Playwright next to the script (tools/node_modules, ignored by git)
// and a PHP server over public_html with a config.php next to it:
//
//   php -S 127.0.0.1:8880 -t public_html
//
// PW_CHANNEL=chrome runs the installed Chrome when Playwright's own browser
// is not downloaded.
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const BASE = process.argv[2] || 'http://127.0.0.1:8880';
const OUT = fileURLToPath(new URL('./out/', import.meta.url));
mkdirSync(OUT, { recursive: true });

const item = (id, name, value, type) => ({
    id, name, value, type, icon: 'assets/icon-sample.png', demand: 'green', trend: '',
});

// Tiers mix the badges on purpose: the catalog must not keep this order.
const FIXTURE = {
    title: 'MAKNEMY\nTIER LIST',
    date: '18.09.2026',
    tiers: [
        { id: 't1', label: 'S', items: [
            item('eclipse', 'Eclipse Dragon', '50000', 'cr'),
            item('token', 'Dragon Token', '25000', 'p'),
            item('slayer', 'Slayer', '20500', 'cs'),
            item('vibes', 'Vibes', '20000', 'vh'),
            item('galaxy', 'Galaxy', '19250', 'ms'),
        ] },
        { id: 't2', label: 'A', items: [
            item('pdragon', 'Permanent Dragon', '14250', 'p'),
            item('meme', 'Meme Fruit', '9000', 'f'),
            item('dragon', 'Dragon Fruit', '6140', 'f'),
            item('notifier', 'Fruit Notifier', '5400', 'gp'),
            item('werewolf', 'Werewolf', '1600', 'cm'),
        ] },
        { id: 't3', label: 'B', items: [
            item('ptiger', 'Permanent Tiger', '7800', 'p'),
            item('pyeti', 'Permanent Yeti', '7800', 'p'),
            item('eagle', 'Eagle Fruit', '0,4', 'f'),
            item('dark', 'Dark Fruit', '0.3', 'f'),
        ] },
    ],
    _rev: 1,
};

// "0,4" is unreadable for the calculator (0), so Eagle Fruit ends the fruits.
const GROUP = {
    fruits: ['Meme Fruit', 'Dragon Fruit', 'Dark Fruit', 'Eagle Fruit'],
    configurators: ['Slayer', 'Werewolf', 'Galaxy', 'Eclipse Dragon'],
    perms: ['Dragon Token', 'Permanent Dragon', 'Permanent Tiger', 'Permanent Yeti'],
    passes: ['Fruit Notifier', 'Vibes'],
};
const ALL = [...GROUP.fruits, ...GROUP.configurators, ...GROUP.perms, ...GROUP.passes];
const ALL_ON = { fruits: true, configurators: true, perms: true, passes: true, all: true };

const browser = await chromium.launch({ channel: process.env.PW_CHANNEL || undefined });
let failed = 0;

function check(name, ok, detail) {
    console.log(`${ok ? 'ok  ' : 'FAIL'} - ${name}${ok ? '' : '   ' + JSON.stringify(detail)}`);
    if (!ok) failed++;
}

const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);
const names = page => page.$$eval('#tcCatalogGrid .tc-cat-name', els => els.map(e => e.textContent));
const pressed = page => page.$$eval('#tcCatalogFilters .chip', els =>
    Object.fromEntries(els.map(e => [e.dataset.f, e.getAttribute('aria-pressed') === 'true'])));
const chip = (page, key) => page.click(`#tcCatalogFilters .chip[data-f="${key}"]`);

async function openCatalog(page) {
    await page.click('.tc-slots[data-side="left"] .tc-slot.is-empty');
    await page.waitForSelector('#tcCatalogGrid .tc-cat-name');
}

async function closeCatalog(page) {
    await page.keyboard.press('Escape');
    await page.waitForSelector('#tcCatalogBackdrop', { state: 'hidden' });
}

async function layout(page) {
    return page.evaluate(() => {
        const box = el => el.getBoundingClientRect();
        const filters = document.getElementById('tcCatalogFilters');
        const chips = [...filters.querySelectorAll('.chip')].map(box);
        const pill = box(document.getElementById('tcCatalogTitle'));
        const close = box(document.getElementById('tcCatalogClose'));
        const mid = r => Math.round(r.top + r.height / 2);
        return {
            pillMid: mid(pill), closeMid: mid(close), chipsMid: mid(chips[0]),
            pillBottom: Math.round(pill.bottom), pillLeft: Math.round(pill.left),
            chipsTop: Math.round(Math.min(...chips.map(c => c.top))), chipsLeft: Math.round(chips[0].left),
            fits: filters.scrollWidth <= filters.clientWidth,
            pageOverflow: document.documentElement.scrollWidth - window.innerWidth,
        };
    });
}

async function session(name, { width, height, mobile = false }, body) {
    const context = await browser.newContext({
        viewport: { width, height },
        isMobile: mobile,
        hasTouch: mobile,
        locale: 'ru-RU',
    });
    const page = await context.newPage();
    page.setDefaultTimeout(10000);
    // php -S serves one connection at a time, and a speculative connection
    // Chromium opens without sending a request can hold it for ~10 s.
    page.setDefaultNavigationTimeout(45000);
    const errors = [];
    page.on('pageerror', e => errors.push(String(e)));
    const json = data => ({ status: 200, contentType: 'application/json', body: JSON.stringify(data) });
    await page.route('**/api/state.php*', r => r.fulfill(json({ rev: 1, likes: 0 })));
    await page.route('**/api/tierlist.php*', r => r.fulfill(json({ tierlist: FIXTURE, likes: 0 })));
    await page.route('**/api/promo.php*', r => r.fulfill(json({ v: 1, rev: 0, campaigns: [] })));
    // The house popup opens 12 s after load whenever the catalog happens to be
    // closed and would swallow the next click. The page checks for
    // window.NX_PROMO_POPUP before using it, so an empty script is safe.
    await page.route('**/js/promo-popup.js*', r => r.fulfill({ status: 200, contentType: 'application/javascript', body: '' }));
    try {
        await page.goto(BASE + '/calculator.php', { waitUntil: 'domcontentloaded' });
        // The grid is built when the catalog opens: opening it before the
        // tier list arrives shows an empty catalog.
        await page.waitForFunction(() => document.getElementById('tcState').hidden);
        await openCatalog(page);
        await body(page);
        check(`${name}: без ошибок на странице`, errors.length === 0, errors);
    } catch (e) {
        check(`${name}: прогон`, false, String(e).split('\n')[0]);
    } finally {
        await context.close();
    }
}

await session('компьютер', { width: 1440, height: 1000 }, async page => {
    check('карточки идут по значку, внутри значка по цене', same(await names(page), ALL), await names(page));
    check('при открытии включено всё', same(await pressed(page), ALL_ON), await pressed(page));

    const row = await layout(page);
    check('кнопки в одной строке с «Каталог» и крестиком',
        Math.abs(row.pillMid - row.chipsMid) <= 2 && Math.abs(row.closeMid - row.chipsMid) <= 2, row);
    check('все пять кнопок видны без прокрутки', row.fits, row);
    await page.screenshot({ path: join(OUT, 'calc-catalog-desktop.png') });

    await chip(page, 'perms');
    check('«Пермы» прячет пермы', same(await names(page), ALL.filter(n => !GROUP.perms.includes(n))), await names(page));
    check('«Все» гаснет, когда группа выключена', same(await pressed(page),
        { fruits: true, configurators: true, perms: false, passes: true, all: false }), await pressed(page));
    await page.screenshot({ path: join(OUT, 'calc-catalog-desktop-noperms.png') });

    await chip(page, 'fruits');
    await chip(page, 'configurators');
    check('включены только пассы', same(await names(page), GROUP.passes), await names(page));
    await chip(page, 'passes');
    check('последнюю группу выключить нельзя',
        same(await names(page), GROUP.passes) && (await pressed(page)).passes === true, await pressed(page));

    await chip(page, 'all');
    check('«Все» возвращает всё', same(await names(page), ALL), await names(page));
    check('«Все» подсвечена', same(await pressed(page), ALL_ON), await pressed(page));

    await page.$eval('#tcCatalogGrid', g => { g.scrollTop = g.scrollHeight; });
    await chip(page, 'passes');
    const scrolled = await page.$eval('#tcCatalogGrid', g => g.scrollTop);
    check('смена фильтра прокручивает сетку в начало', scrolled === 0, scrolled);

    await chip(page, 'passes');
    await chip(page, 'perms');
    await page.fill('#tcCatalogSearch', 'dragon');
    await page.waitForTimeout(400);
    check('поиск идёт внутри включённых групп',
        same(await names(page), ['Dragon Fruit', 'Eclipse Dragon']), await names(page));

    await closeCatalog(page);
    await openCatalog(page);
    const again = { pressed: await pressed(page), names: await names(page) };
    check('выбор фильтра переживает повторное открытие',
        again.pressed.perms === false && same(again.names, ALL.filter(n => !GROUP.perms.includes(n))), again);
    check('поиск при повторном открытии сброшен', (await page.inputValue('#tcCatalogSearch')) === '',
        await page.inputValue('#tcCatalogSearch'));

    await closeCatalog(page);
    await page.click('#langSwitch [data-lang="en"]');
    await openCatalog(page);
    const labels = await page.$$eval('#tcCatalogFilters .chip', els => els.map(e => e.textContent));
    check('подписи кнопок переводятся', same(labels, ['Fruits', 'Configurators', 'Perms', 'Passes', 'All']), labels);
    const group = await page.getAttribute('#tcCatalogFilters', 'aria-label');
    check('название группы переводится', group === 'Filter by category', group);
    const en = await layout(page);
    check('английские подписи тоже влезают', en.fits, en);
});

for (const width of [390, 360]) {
    await session(`телефон ${width}`, { width, height: 844, mobile: true }, async page => {
        const lay = await layout(page);
        check(`${width}: кнопки отдельной строкой под «Каталог»`, lay.chipsTop >= lay.pillBottom, lay);
        check(`${width}: все пять кнопок влезают без прокрутки`, lay.fits, lay);
        check(`${width}: кнопки начинаются от левого края, как «Каталог»`, Math.abs(lay.chipsLeft - lay.pillLeft) <= 2, lay);
        check(`${width}: у страницы нет горизонтальной прокрутки`, lay.pageOverflow <= 0, lay);
        await page.screenshot({ path: join(OUT, `calc-catalog-phone-${width}.png`) });

        await chip(page, 'fruits');
        check(`${width}: касание кнопки прячет группу`,
            same(await names(page), ALL.filter(n => !GROUP.fruits.includes(n))), await names(page));
    });
}

await browser.close();
console.log(failed ? `\n${failed} FAILED` : '\nALL OK');
process.exit(failed ? 1 : 0);
```

- [ ] **Step 3: запустить сервер**

Run (в фоне, `run_in_background`): `cd "$WT" && /c/xampp/php/php.exe -S 127.0.0.1:8891 -t public_html`
Проверка: `curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8891/calculator.php` → `200`.

- [ ] **Step 4: прогнать проверку**

Run: `cd "$WT" && PW_CHANNEL=chrome node tools/check-calc-catalog-ui.mjs http://127.0.0.1:8891`
Expected: все строки `ok`, в конце `ALL OK`, код выхода 0.

Если падает только `360: все пять кнопок влезают без прокрутки` — в `@media (max-width: 900px)` заменить `.tc-cat-filters .chip { padding: 7px; font-size: 13px; }` на `.tc-cat-filters .chip { padding: 7px 6px; font-size: 13px; }`, прогнать снова и закоммитить отдельно (`fix(calculator): …`). `?v=` второй раз не поднимать: на бою по-прежнему 23, ветка уезжает одним мержем. Любой другой FAIL — ошибка в коде Task 1–3: чинить там, не в проверке.

- [ ] **Step 5: посмотреть на скриншоты**

Открыть (Read tool) `tools/out/calc-catalog-desktop.png`, `calc-catalog-desktop-noperms.png`, `calc-catalog-phone-390.png`, `calc-catalog-phone-360.png`. Ожидается: на компьютере кнопки между «Каталог» и ✕, включённые — синие с кольцом; на телефоне кнопки строкой под «Каталог», ни одна не обрезана, кольца не срезаны сверху и снизу.

- [ ] **Step 6: скриншоты на живых данных (не коммитится)**

Спека просит посмотреть и на боевой тирлист: настоящие иконки, 122 предмета. Скрипт в скратчпаде `$SP` = `C:/Users/nural/AppData/Local/Temp/claude/C--Users-nural-Desktop-Nexus-Tier-List/ab50761e-8b4d-4fd0-8f72-3cbb372ba095/scratchpad`, файл `$SP/shot-catalog-live.mjs` (Write tool):

```js
import { createRequire } from 'node:module';
const require = createRequire(process.env.WT + '/tools/check.mjs');
const { chromium } = require('playwright');

const BASE = process.argv[2];
const OUT = process.argv[3];
const browser = await chromium.launch({ channel: 'chrome' });
const live = async route => {
    const u = new URL(route.request().url());
    await route.fulfill({ response: await route.fetch({ url: 'https://maknemy.com' + u.pathname + u.search }) });
};
for (const [name, viewport, mobile] of [['desktop', { width: 1440, height: 900 }, false], ['phone', { width: 390, height: 844 }, true]]) {
    const context = await browser.newContext({ viewport, isMobile: mobile, hasTouch: mobile, deviceScaleFactor: mobile ? 2 : 1 });
    const page = await context.newPage();
    page.setDefaultNavigationTimeout(45000);
    for (const pattern of ['**/api/tierlist.php*', '**/api/state.php*', '**/api/promo.php*', '**/images/**']) {
        await page.route(pattern, live);
    }
    await page.route('**/js/promo-popup.js*', r => r.fulfill({ status: 200, contentType: 'application/javascript', body: '' }));
    await page.goto(BASE + '/calculator.php', { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(() => document.getElementById('tcState').hidden);
    await page.click('.tc-slots[data-side="left"] .tc-slot.is-empty');
    await page.waitForSelector('#tcCatalogGrid .tc-cat-name');
    await page.waitForTimeout(1500);
    await page.screenshot({ path: `${OUT}/live-${name}.png` });
    const order = await page.$$eval('#tcCatalogGrid .tc-cat-card', els => els.map(e => e.querySelector('.tc-cat-badge').alt));
    const runs = order.filter((code, i) => code !== order[i - 1]);
    console.log(name, 'значки по порядку:', runs.join(' '));
    await page.click('#tcCatalogFilters .chip[data-f="perms"]');
    await page.waitForTimeout(800);
    await page.screenshot({ path: `${OUT}/live-${name}-noperms.png` });
    await context.close();
}
await browser.close();
```

Run: `WT="$WT" node "$SP/shot-catalog-live.mjs" http://127.0.0.1:8891 "$SP"`
Expected: обе строки `значки по порядку: FR CS CM MS CR PM GP VH` (каждый значок один раз, ровно в этом порядке); на скриншотах `live-*.png` первыми идут фрукты от Meme Fruit вниз по цене, на `live-*-noperms.png` нет карточек PM.

- [ ] **Step 7: весь набор тестов**

Обёртка для GD (без неё `images_test.php` даёт ложные падения) — файл `$SP/php-gd.sh` (Write tool):

```sh
#!/bin/sh
exec /c/xampp/php/php.exe -d extension=gd "$@"
```

Run: `cd "$WT" && PHP="$SP/php-gd.sh" bash tests/run_all.sh`
Expected: в конце `ALL UNIT TESTS PASSED`.

- [ ] **Step 8: коммит**

```bash
cd "$WT"
git status --short   # только tools/check-calc-catalog-ui.mjs
git add tools/check-calc-catalog-ui.mjs
git commit -F - <<'EOF'
test(calculator): проверка каталога в браузере

Порядок карточек по значку и цене, каждая кнопка фильтра, правило
последней группы, фильтр вместе с поиском, выбор после повторного
открытия, английские подписи, раскладка на 1440, 390 и 360.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
```

---

## После плана

`superpowers:finishing-a-development-branch`: ветку `feat/calc-catalog-sort-filter` — в `maknemy`, PR в `kannurali/MaknemyTierlist` на `master`; мержит владелец. Перед пушем — `gh pr list` на открытые PR с правками `calculator.php` (на 2026-09-18 такой один, #70, и строк с этими `?v=` он не трогает).
