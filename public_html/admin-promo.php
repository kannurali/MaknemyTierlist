<?php
// Панель управления рекламой. Прежний адрес /promo-admin.html редиректит сюда.
//
// Вход больше не спрашивает сама страница: пароль проверяет сервер до отдачи
// разметки, поэтому форма входа и её JS из panel'и убраны — гость получает
// экран входа от admin_page_guard(), а не пустую панель с оверлеем.
require_once __DIR__ . '/api/lib/admin_page.php';
admin_page_guard('Панель рекламы');

header('Content-Type: text/html; charset=utf-8');
$nav = admin_nav('promo');
echo <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="color-scheme" content="dark" />
<meta name="robots" content="noindex,nofollow" />
<title>Реклама — панель управления</title>
<link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48" />
<!-- Своя таблица стилей, а не styles.css: тот качает каждый посетитель сайта,
     и растить его на стили внутренней формы незачем. Пути абсолютные —
     страница живёт на /admin/promo, от относительных они уехали бы в
     /admin/css/. -->
<link rel="stylesheet" href="/css/admin-shell.css?v=1" />
<link rel="stylesheet" href="/css/promo-admin.css?v=2" />
</head>
<body>
{$nav}

<div class="app" id="app">
  <header class="top">
    <div class="brand">РЕКЛАМА<span>панель</span></div>
    <div class="top-actions">
      <button class="btn" id="btnNew" type="button">＋ Кампания</button>
      <button class="btn" id="btnImportLegacy" type="button"
              title="Перенести старый баннер из тирлиста в кампанию">Импортировать текущий баннер</button>
      <button class="btn" id="btnPreview" type="button"
              title="Открыть сайт с НЕсохранёнными правками — можно показать рекламодателю до публикации">Предпросмотр на сайте</button>
      <button class="btn primary" id="btnSave" type="button">Сохранить</button>
      <span class="hint" id="saveHint"></span>
    </div>
  </header>

  <main class="cols">
    <aside class="list-col">
      <div class="list-head">Кампании <span class="muted" id="listCount"></span></div>
      <ul class="list" id="list"></ul>
      <p class="muted small" id="emptyHint" hidden>Пока пусто. Нажмите «＋ Кампания».</p>
    </aside>

    <section class="edit-col" id="editor" hidden>
      <div class="row">
        <label class="f grow">Название <span class="muted">(для вас)</span>
          <input type="text" id="fName" placeholder="Магазин · август" />
        </label>
        <label class="f">Рекламодатель
          <input type="text" id="fAdvertiser" placeholder="Название рекламодателя" />
        </label>
      </div>

      <div class="row">
        <label class="f check"><input type="checkbox" id="fEnabled" /> Показывать</label>
        <label class="f grow">Вес в ротации <span class="muted" id="weightShare"></span>
          <input type="range" id="fWeight" min="1" max="10" step="1" />
        </label>
      </div>

      <div class="row">
        <label class="f">Начало <input type="date" id="fStart" /></label>
        <label class="f">Конец <input type="date" id="fEnd" /></label>
        <div class="f grow note" id="windowNote"></div>
      </div>

      <label class="f">Ссылка
        <div class="withbtn">
          <input type="url" id="fHref" placeholder="https://example.com/" />
          <button class="btn small" type="button" id="btnUtm" title="Добавить UTM-метки">UTM</button>
        </div>
        <div class="err" id="hrefErr" hidden>Такую ссылку сайт открывать не будет.</div>
      </label>

      <div class="row">
        <label class="f grow">Текст объявления
          <input type="text" id="fText" placeholder="Короткая строка от рекламодателя" />
        </label>
        <label class="f">Кнопка
          <input type="text" id="fCta" placeholder="Перейти" />
        </label>
      </div>

      <label class="f">Токен маркировки <span class="muted">(erid — присылает рекламодатель; пусто — подписи не будет)</span>
        <input type="text" id="fErid" placeholder="2VtzquZgWvo" spellcheck="false" />
        <div class="err" id="eridErr" hidden>Такой токен сайт не покажет: допустимы латиница, цифры, дефис и подчёркивание.</div>
      </label>

      <label class="f">Заметки <span class="muted">(видно только вам — на сайт не уходят)</span>
        <textarea id="fNotes" rows="2" placeholder="оплачено до 20.09, контакт @…"></textarea>
      </label>

      <div class="slots" id="slots"></div>

      <div class="row popup-cfg">
        <label class="f">Задержка окна, с <input type="number" id="fDelay" min="5" max="60" step="1" /></label>
        <label class="f">Не чаще, ч <input type="number" id="fCap" min="1" max="720" step="1" /></label>
        <label class="f">Показов в неделю <input type="number" id="fWeek" min="1" max="50" step="1" /></label>
      </div>

      <div class="row end">
        <button class="btn" id="btnDup" type="button">Дублировать</button>
        <button class="btn danger" id="btnDel" type="button">Удалить</button>
      </div>
    </section>
  </main>
</div>

<script src="/js/promo.js?v=2"></script>
<script src="/js/promo-admin.js?v=3"></script>
</body>
</html>
HTML;
