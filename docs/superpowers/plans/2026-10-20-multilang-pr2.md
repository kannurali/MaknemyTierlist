# Мультиязычность, PR 2 «Языки» — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** сайт работает на 10 языках (ru, en, de, fr, es, it, pt, pl, tr, uk): интерфейс, страница `/ads`, разметка для поисковиков, выбор языка из меню ☰ и автоопределение по браузеру.

**Architecture:** основа из PR 1 (#188, #189) не меняется: язык по приставке адреса, `t/te/ta/u` в PHP, словари `lang/*.json`, собранные `js/i18n/*.js`. PR 2 добавляет 8 словарей, расширяет списки языков в PHP/JS/`.htaccess`, переводит последние зашитые русские тексты и `/ads` на ключи, делает JS независимым от пары ru/en (локаль, склонения, выбор контента), ставит переключатель «Язык ▾» в меню ☰ и чинит шрифты для европейских букв.

**Tech Stack:** PHP 7.4/8.0, Apache mod_rewrite / LiteSpeed, ванильный JS, `Intl.PluralRules`/`Intl.DateTimeFormat`, node:test, Playwright 1.62.1 + Chrome.

**Спека:** `docs/superpowers/specs/2026-10-19-multilang-design.md`. Решения пользователя 2026-10-20: переключатель — кнопка «Язык ▾» со списком всех языков; `/ads` переводится в этом PR.

## Global Constraints

- Языки: `['ru', 'en', 'de', 'fr', 'es', 'it', 'pt', 'pl', 'tr', 'uk']`; ru без приставки, остальные `/<код>/…`.
- Названия языков в меню — на самих языках: Русский, English, Deutsch, Français, Español, Italiano, Português, Polski, Türkçe, Українська.
- og:locale: ru_RU, en_US, de_DE, fr_FR, es_ES, it_IT, pt_BR, pl_PL, tr_TR, uk_UA. Локали форматирования в JS: ru-RU, en-GB, de-DE, fr-FR, es-ES, it-IT, pt-BR, pl-PL, tr-TR, uk-UA.
- Языки СНГ, остающиеся на русском: `ru|kk|uz|ky|be|az|hy|ka|tg|tk` (uk больше не в этом списке — у него своя версия).
- Контент из базы (новости, розыгрыши, описания предметов) до PR 3 есть только ru/en: на любом нерусском языке показывается EN, без него RU.
- Только сервер: ключи с приставками `meta.`, `ld.`, `og.`, `ads.` не попадают в `js/i18n/*.js`.
- Русская отрисовка страниц не меняется, кроме перечисленного в задачах (снимки `tools/i18n_snapshot.php`).
- Всё из Global Constraints плана PR 1 действует: PHP 7.4, без комментариев в отдаваемых js/css/HTML, CSP, `?v=` выше master и открытых PR, тесты `/c/xampp/php/php.exe -d extension=gd`, известное локальное падение `chat_test.php` (1), скрипты через Write, `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Рабочее дерево `$WT` (как в PR 1), ветка `feat/i18n-languages` от master `2333fe1`. Перед снимками и многострочными заменами рабочие файлы в LF (`node ../to-lf.mjs`, затем `git add -u` для обновления статов).

---

### Task 1: JS не зависит от пары ru/en

**Files:** `public_html/js/i18n.js`, `app.js`, `calculator-page.js`, `chat-page.js`, `content.js`, `giveaway.js`, `gw-card.js`, `gw-notify.js`, `news.js`, `news-blocks.js`, `profile-chart.js`, `profile-notify.js`, `profile-page.js`, `promo.js`, `stock-page.js`, `topbar.js`; `tests/i18n_test.mjs`; страницы (`?v=`).

**Interfaces:** Produces `I18N.locale(lang?) → "ru-RU"|…`, `I18N.plural(n, lang?) → "one"|"few"|"many"`.

- [ ] Тест в `tests/i18n_test.mjs`:

```js
test('locale maps languages to formatting locales', () => {
    assert.equal(I18N.locale('ru'), 'ru-RU');
    assert.equal(I18N.locale('en'), 'en-GB');
    assert.equal(I18N.locale('xx'), 'ru-RU');
    assert.equal(I18N.locale(), 'ru-RU');
});

test('plural follows the language rules and keeps the three dictionary slots', () => {
    const ru = [1, 2, 5, 11, 21, 22, 25].map(n => I18N.plural(n, 'ru'));
    assert.deepEqual(ru, ['one', 'few', 'many', 'many', 'one', 'few', 'many']);
    const en = [1, 2, 5, 21].map(n => I18N.plural(n, 'en'));
    assert.deepEqual(en, ['one', 'many', 'many', 'many']);
});
```

- [ ] В `i18n.js` после `var MIGRATED_KEY …`:

```js
  var LOCALES = { ru: "ru-RU", en: "en-GB", de: "de-DE", fr: "fr-FR", es: "es-ES", it: "it-IT", pt: "pt-BR", pl: "pl-PL", tr: "tr-TR", uk: "uk-UA" };
```
функции перед `var api`:
```js
  function locale(lang) {
    var l = lang || pageLang();
    return LOCALES[l] && LANGS.indexOf(l) >= 0 ? LOCALES[l] : LOCALES[DEFAULT_LANG];
  }

  function plural(n, lang) {
    var cat = "other";
    try { cat = new Intl.PluralRules(locale(lang)).select(n); } catch (_) {}
    return cat === "one" ? "one" : cat === "few" ? "few" : "many";
  }
```
и в `api`: `locale: locale, plural: plural`.

- [ ] Замены в JS (язык UI — `pageLang()`, формат — `locale()`, склонения — `plural()`, контент — «не ru → en»):
  - `app.js`: `likeCount.toLocaleString("ru-RU")` → `likeCount.toLocaleString(I18N.locale())`.
  - `calculator-page.js`: `toLocaleString(lang === "en" ? "en-US" : "ru-RU")` → `toLocaleString(I18N.locale(lang))`.
  - `chat-page.js`: `function lang() { return document.documentElement.lang === 'en' ? 'en' : 'ru'; }` → `function lang() { return i18n ? i18n.pageLang() : 'ru'; }`; `toLocaleString(lang() === 'en' ? 'en-GB' : 'ru-RU', {` → `toLocaleString(i18n ? i18n.locale() : 'ru-RU', {`.
  - `content.js`: `return lang === "en" ? (en || ru) : (ru || en);` → `return lang === "ru" ? (ru || en) : (en || ru);`.
  - `giveaway.js`: тело `lang()` → `return root.I18N ? root.I18N.pageLang() : "ru";`; `pick`: `(l === "en" ? obj.en || obj.ru : obj.ru || obj.en)` → `(l === "ru" ? obj.ru || obj.en : obj.en || obj.ru)`; `toLocaleString(lang() === "en" ? "en-GB" : "ru-RU", opts)` → `toLocaleString(root.I18N ? root.I18N.locale() : "ru-RU", opts)`; тело `plural(n)` → `return root.I18N ? root.I18N.plural(n) : "many";`. Неиспользуемый после этого `LANG_KEY` убрать.
  - `gw-card.js`: `toLocaleString(GW.lang() === "en" ? "en-US" : "ru-RU")` → `toLocaleString(root.I18N ? root.I18N.locale(GW.lang()) : "ru-RU")`.
  - `gw-notify.js`: `lang()` → `return window.I18N ? window.I18N.pageLang() : "ru";`; тело склонения → `return window.I18N ? window.I18N.plural(n) : "many";`; `toLocaleString(lang() === "en" ? "en-US" : "ru-RU")` → `toLocaleString(window.I18N ? window.I18N.locale() : "ru-RU")`.
  - `news.js` `pickLang`: `return lang === "en"` с ветками EN/RU → `return lang !== "ru"` (ветки на месте).
  - `news-blocks.js`: `var self = lang === "en" ? "en" : "ru";` → `var self = lang === "ru" ? "ru" : "en";`; `return lang === "en" ? { self: "en", other: "ru" } : { self: "ru", other: "en" };` → `return lang === "ru" ? { self: "ru", other: "en" } : { self: "en", other: "ru" };`.
  - `profile-chart.js`: `lang` → `() => (window.I18N ? window.I18N.pageLang() : 'ru')`; в `monthLabel` и в подписи дня для языков кроме ru/en — `Intl.DateTimeFormat(I18N.locale(), {month: 'long'})` и `{day: 'numeric', month: 'long'}` по дате `new Date(y, m - 1, 15)`; ru/en — прежние массивы.
  - `profile-notify.js`, `profile-page.js`: `lang()` → `return window.I18N ? window.I18N.pageLang() : 'ru';`.
  - `promo.js`: в `gwcT` — `var lang = root.I18N ? root.I18N.pageLang() : "ru";`; тело `gwcPlural` → `return root.I18N ? root.I18N.plural(n) : "many";` (если задан `g.lang` — `root.I18N.plural(n, g.lang())`).
  - `stock-page.js`: `toLocaleString(lang === "en" ? "en-US" : "ru-RU")` → `toLocaleString(I18N.locale(lang))`.
  - `topbar.js`: `d.toLocaleString(uiLang() === "en" ? "en-GB" : "ru-RU", opts)` → `d.toLocaleString(window.I18N ? window.I18N.locale() : "ru-RU", opts)`.
- [ ] `node --check` для всех правленых файлов; полный прогон; закреплённые в тестах старые строки (если упадут) обновить на новые; `?v=` правленых js поднять (скрипт `bump.mjs` из PR 1, номера — выше master и открытых PR).
- [ ] Commit `feat(i18n): локаль, склонения и выбор контента не завязаны на ru/en`.

---

### Task 2: Последние зашитые русские тексты, JSON-LD, превью тирлиста

**Files:** `public_html/api/lib/i18n.php` (`tj`, `th`), `giveaways.php`, `profile.php`, `calculator.php`, `index.php`, `api/lib/og.php`, страницы с JSON-LD (`home index calculator stock trading`), `chat.php` (убрать JSON-LD калькулятора), `tools/i18n_build.php` (серверные ключи), `lang/ru.json`, `lang/en.json`, `tests/lib.php` (`i18n_flatten` для `tj`), тесты.

**Interfaces:** Produces `tj(string $key, array $vars = []): string` — строка для подстановки внутрь JSON-литерала (`json_encode` без кавычек, `JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG`); `th(string $key, array $vars = []): string` — доверенная разметка из словаря как есть (только для ключей `ads.*`); `I18N_SERVER_ONLY = ['meta.', 'ld.', 'og.', 'ads.']`.

- [ ] `giveaways.php`: текст `#gwNtTitle` → `<?= te('gw.notify.ask') ?>`; `<option value="60">` → `te('gw.notify.min.many')`, `3600` → `te('gw.notify.hour.many')`, `86400` → `te('gw.notify.day.many')`; `#gwNtPlacesU` → `te('gw.notify.place.many')`.
- [ ] `profile.php` `#pfGate`: текст → `<?= te('profile.login') ?>`.
- [ ] `calculator.php` `#tcThreshold`: текст → `<?= te('calc.thresholdNote', ['pct' => '5']) ?>`.
- [ ] `index.php` `tierlist_og_fallback()`: строки → `t('og.tierlist.title')`, `t('og.tierlist.desc')`; `api/lib/og.php` `og_tierlist_meta()`: `'-тир'`, `' на '` и запасной заголовок → ключи `og.tierlist.tierTitle` (`"Maknemy Tier List — {tier}-тир{date}"`), `og.tierlist.onDate` (`" на {date}"`), `og.tierlist.title`. Русский вывод `og_test.php` обязан остаться прежним.
- [ ] JSON-LD: в `home index calculator stock trading` строковые значения с кириллицей (кроме `alternateName` — это варианты бренда) → `<?= tj('ld.<page>.<поле>') ?>` (описание главной — `tj('meta.home.ogDesc')`), `"inLanguage": "ru"` → `"inLanguage": "<?= i18n_lang() ?>"`, адреса страниц `https://maknemy.com/<путь>` → `<?= i18n_url('/<путь>') ?>`. Обёртку «только по-русски» снять. В `chat.php` блок JSON-LD (копия калькуляторного) удалить. `i18n_flatten` в `tests/lib.php` учит `tj`: `<?= tj('k') ?>` → русское значение через тот же `json_encode`.
- [ ] `tools/i18n_build.php`: в `build_dict_js` выкидывать ключи с приставками `I18N_SERVER_ONLY` (константа в `i18n.php`). Тест: в `js/i18n/ru.js` нет `"meta.` и `"ads.`.
- [ ] Тест страниц (`tests/i18n_pages_test.php`): JSON-LD есть на всех языках, разбирается `json_decode`, `inLanguage` равен языку страницы; на `/en/chat` и `/chat` JSON-LD нет; в EN-снимках всех страниц, кроме `/ads`, нет кириллицы вне `<script>` и `alternateName`.
- [ ] Снимки: русский вывод меняется только у чата (нет JSON-LD) и у `/calculator` не меняется (`te` с `pct` даёт ту же строку).
- [ ] Commit `feat(i18n): последние зашитые тексты, JSON-LD и превью тирлиста из словаря`.

---

### Task 3: Страница `/ads` на ключах

**Files:** `public_html/promote.php`, `lang/ru.json`, `lang/en.json`, `tools/i18n_build.php` (`/ads` обратно в `SITEMAP_PAGES`), тесты.

- [ ] Одноразовый `tools/i18n_ads_once.php` (не коммитится): в кусках HTML вне PHP каждый элемент, у которого содержимое на одной строке и в нём есть кириллица, получает `<?= th('ads.NNN') ?>` (NNN — порядковый номер, три цифры), а русское содержимое как есть уходит в `lang/ru.json`; атрибуты `alt/aria-label/title/placeholder` с кириллицей → `<?= ta('ads.NNN') ?>`. Многострочные элементы с кириллицей — отчётом, переводятся руками тем же способом.
- [ ] EN-перевод всех `ads.*` (пишу сам, разметку внутри строк сохраняю один в один).
- [ ] Убрать `noindex` для нерусских версий и вернуть `<?= i18n_alternates('/ads') ?>`; `/ads` обратно в `SITEMAP_PAGES`; `tests/i18n_pages_test.php`: `/ads` индексируется, проверка noindex удаляется.
- [ ] Тест разметки ключей `ads.*`: во всех языках последовательность тегов с атрибутами совпадает с русской (`preg_match_all('~<[^>]+>~')`), разрешены только `b i strong em a br span small sup`.
- [ ] Снимок: русская `/ads` байт в байт прежняя; в EN-снимке `/ads` нет кириллицы.
- [ ] Commit `feat(i18n): страница «Реклама» на ключах словаря`.

---

### Task 4: Переключатель «Язык ▾» в меню ☰

**Files:** `public_html/api/lib/i18n.php` (`i18n_path()`, `i18n_lang_menu()`, `I18N_NAMES`), 12 страниц (блок `#langSwitch` → вызов внутри `nav.mk-top-bar` после `ul.mk-nav`), `js/topbar.js` (не переносить `langSwitch` в меню аватара; открыть/закрыть список), `css/topbar.css` + `css/hw/topbar.css`, `lang/*.json` (`lang.menu` = «Язык» / «Language»), тесты.

**Interfaces:** Produces `i18n_path(): string` — текущий путь с query без языковой приставки; `i18n_lang_menu(): string` — разметка:

```html
<div class="mk-lang" id="langSwitch">
  <button class="mk-lang-btn" type="button" aria-haspopup="true" aria-expanded="false" aria-controls="mkLangList">
    <span class="mk-lang-label" data-i18n="lang.menu">Язык</span>
    <svg class="mk-lang-chev" viewBox="0 0 12 8" aria-hidden="true"><path d="M1 1.5 6 6.5 11 1.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
  </button>
  <ul class="mk-lang-list" id="mkLangList" hidden>
    <li><a class="mk-lang-item" href="/tierlist" hreflang="ru" lang="ru" data-lang="ru" aria-current="true">Русский</a></li>
    <li><a class="mk-lang-item" href="/en/tierlist" hreflang="en" lang="en" data-lang="en">English</a></li>
    …
  </ul>
</div>
```
Ссылки настоящие (работают без JS и видны поисковику); `js/i18n.js` уже перехватывает нажатие на `#langSwitch [data-lang]`, ставит куку и переходит.

- [ ] PHP-тест: `i18n_path()` для `/en/news/92?x=1` → `/news/92?x=1`, для `/en` → `/`; меню на `/en/tierlist` содержит 10 ссылок с правильными адресами, текущая — `aria-current="true"`; у всех 12 страниц вызов стоит внутри `nav.mk-top-bar`, старых чипов `data-lang="ru"` с классом `chip` нет.
- [ ] Визуал — дизайн-агент (frontend-design): кнопка в стиле пилюль меню, список поверх, на телефоне и компьютере, обе темы (обычная и Хэллоуин через `php tools/halloween_css.php`). Скриншоты — Playwright через локальный Apache.
- [ ] JS (`topbar.js`): кнопка открывает/закрывает список, `Escape` и нажатие мимо закрывают, `aria-expanded` синхронен; `langBox` больше не переносится в меню аватара.
- [ ] Снимки: у всех страниц меняется только блок языка (чипы → меню); `?v=` у `topbar.js` и `topbar.css` (+ hw).
- [ ] Commit `feat(i18n): выбор языка — «Язык ▾» в меню ☰`.

---

### Task 5: Переводы на 8 языков

**Files:** `public_html/lang/{de,fr,es,it,pt,pl,tr,uk}.json`; `tests/i18n_test.mjs` (проверки для всех языков).

- [ ] Восемь параллельных агентов-переводчиков, по одному на язык, каждый пишет только свой `lang/<код>.json`. Вход: `lang/ru.json` (источник смысла) и `lang/en.json` (сверка), правила:
  - те же ключи в том же порядке; `{подстановки}` и HTML-теги в `ads.*` — без изменений;
  - названия предметов Blox Fruits не переводить (Dragon, Kitsune, Leopard…), бренды тоже (Maknemy, Roblox, Telegram, Mirage);
  - игровой сленг — так, как говорят игроки этого языка (часто англицизмы: trade, perm, gamepass, stock, value, demand);
  - тон — дружелюбный, на «ты»/«вы» по норме языка для сайтов игроков (de: du, fr: tu, es: tú, it: tu, pt-BR: você, pl: ty, tr: sen, uk: ви);
  - португальский — бразильский; испанский — нейтральный;
  - склонения `*.one/few/many`: в `few` для языков без этой формы — та же строка, что в `many`;
  - длина подписей кнопок и пунктов меню — не длиннее английской больше чем на треть, иначе короче синонимом.
- [ ] Проверки в `tests/i18n_test.mjs` (для всех языков): паритет ключей, подстановок и тегов; нет пустых; в латинских языках нет кириллицы; в `uk` не больше 5% строк, совпадающих с русскими (кроме брендов и чисел).
- [ ] Ручная выборочная сверка: по 30 строк каждого языка (меню, кнопки, тирлист, калькулятор, `/ads`).
- [ ] Commit `feat(i18n): словари de fr es it pt pl tr uk`.

---

### Task 6: Включить 10 языков

**Files:** `public_html/api/lib/i18n.php` (`I18N_LANGS`, `I18N_OG_LOCALES`, `I18N_NAMES`), `js/i18n.js` (`LANGS`), `.htaccess`, `tools/i18n_build.php` (собрать), `js/i18n/*.js`, `sitemap.xml`, тесты.

- [ ] `.htaccess`: кука `nx_lang=(ru|en|de|fr|es|it|pt|pl|tr|uk)`; список СНГ без `uk`; на каждый язык из `en de fr es it pt pl tr uk` правило
  ```apache
  RewriteCond %{ENV:NX_PICK} ^$
  RewriteCond %{HTTP:Accept-Language} ^\s*de([-_,;\s]|$) [NC]
  RewriteRule ^ - [E=NX_PICK:de]
  ```
  (значение — константа, а не захват: регистр заголовка бывает любым); затем общий `E=NX_PICK:en`; условия перенаправления и все правила приставок — `(en|de|fr|es|it|pt|pl|tr|uk)`.
- [ ] `tests/i18n_routes_test.php` берёт список из `I18N_LANGS` (уже так) и проверяет правило каждого языка.
- [ ] `php tools/i18n_build.php` → 10 словарей JS, sitemap на 10 языков.
- [ ] Локальный Apache: `curl-i18n.sh` + новые строки (`de`, `uk`, `pt-BR`, `TR` в верхнем регистре, `pl` с кукой `ru`).
- [ ] Commit `feat(i18n): включены de fr es it pt pl tr uk`.

---

### Task 7: Шрифты

**Files:** `css/base.css`, `css/topbar.css` (+ `css/hw/*` через `php tools/halloween_css.php`), `assets/fonts/Oswald/oswald-latin-ext.woff2` (скачать с Google Fonts — только с разрешения пользователя), страницы (`?v=`).

- [ ] `base.css`: для латинских языков `--proto` берёт Bootshaus (в Proto Sans нет диакритики):
  ```css
  :root:lang(de), :root:lang(fr), :root:lang(es), :root:lang(it), :root:lang(pt), :root:lang(pl), :root:lang(tr) { --proto: "Bootshaus", "Arial Narrow", Impact, sans-serif; }
  :root:lang(tr) { --display: "Bootshaus", "Oswald", "Proto Sans", "Arial Narrow", Impact, sans-serif; }
  ```
  (турецкая `ı` есть в уже загруженном Oswald latin, U+0131).
- [ ] `topbar.css`: третий `@font-face` Oswald с `oswald-latin-ext.woff2`, `unicode-range: U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF`. Без разрешения на скачивание — пропустить, польские/турецкие буквы в Oswald останутся из запасного шрифта.
- [ ] Проверка: `tests/fonts_test.php` (если проверяет список шрифтов — дополнить), снимки глифов через Playwright: на `/pl` и `/tr` в шапке и профиле нет «квадратиков».
- [ ] Commit `feat(i18n): шрифты для европейских букв`.

---

### Task 8: Вёрстка на всех языках

**Files:** `tools/check-i18n-layout.mjs`, правки CSS по результатам.

- [ ] Скрипт: локальный Apache, 12 страниц × 10 языков × (1280 и 390 px), API подменено пустыми ответами; для каждой страницы — скриншот и список элементов, у которых `scrollWidth > clientWidth + 1` среди `a, button, h1, h2, h3, p, span, li, label` с видимым текстом, плюс горизонтальная прокрутка документа.
- [ ] Разобрать переполнения: короче перевод (в словаре) или `overflow-wrap: anywhere` / `hyphens: auto` в конкретном месте CSS. Русские снимки не меняются.
- [ ] Посмотреть глазами листы скриншотов de, pl, tr, uk (самые длинные и с особыми буквами).
- [ ] Commit `fix(i18n): вёрстка под длинные слова`.

---

### Task 9: Финал

- [ ] `tools/check-i18n-ui.mjs` дополнить: `pl`-браузер → `/pl/…`, `uk` → `/uk/…`, меню ☰ «Язык ▾» открывает список и переводит на `/de/…`.
- [ ] Полный прогон, `php tools/i18n_build.php --check`, аудит `?v=`, `git merge-tree` с открытыми PR.
- [ ] Push `feat/i18n-languages`, PR с проверками на бою: `/de/tierlist` и `/uk/` по-своему, автоопределение `de`/`uk`, меню, sitemap.
