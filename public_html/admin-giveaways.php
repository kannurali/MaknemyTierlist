<?php
// Розыгрыши — /admin/giveaways. Только admin_ids.
//
// Страница — каркас: список, редактор и предпросмотр наполняет
// js/giveaway-admin.js по ответам /api/giveaway_admin.php. Предпросмотр
// рисует та же карточка (js/giveaway.js), что окно и страница /giveaways,
// поэтому владелец видит ровно то, что увидят посетители.
//
// Пути абсолютные: страница живёт на /admin/giveaways, относительные уехали
// бы в /admin/css/.
require_once __DIR__ . '/api/lib/admin_page.php';
admin_page_guard();

header('Content-Type: text/html; charset=utf-8');
$nav = admin_nav('giveaways');
echo <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="color-scheme" content="dark" />
<meta name="robots" content="noindex,nofollow" />
<title>Розыгрыши — панель управления</title>
<link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48" />
<link rel="stylesheet" href="/css/admin-shell.css?v=4" />
<link rel="stylesheet" href="/css/promo-admin.css?v=4" />
<link rel="stylesheet" href="/css/giveaway.css?v=6" />
<link rel="stylesheet" href="/css/giveaway-admin.css?v=7" />
</head>
<body>
{$nav}

<div class="app" id="app">
  <header class="top">
    <div class="brand">РОЗЫГРЫШИ<span>панель</span></div>
    <div class="top-actions">
      <button class="btn" id="gwNew" type="button">＋ Розыгрыш</button>
      <a class="btn" href="/giveaways" target="_blank" rel="noopener">Страница на сайте ↗</a>
      <a class="btn" href="/giveaways?test" target="_blank" rel="noopener">Тест ↗</a>
      <span class="hint" id="gwHint"></span>
    </div>
  </header>

  <p class="ga-alert" id="gwAlert" hidden></p>

  <main class="cols ga-cols">
    <aside class="list-col">
      <div class="list-head">Розыгрыши <span class="muted" id="gwListCount"></span></div>
      <ul class="list" id="gwList"></ul>
      <p class="muted small" id="gwEmpty" hidden>Пока пусто. Нажмите «＋ Розыгрыш».</p>

      <div class="ga-channels">
        <div class="list-head">Каналы бота</div>
        <ul class="ga-ch-list" id="gwChannels"></ul>
        <p class="muted small" id="gwChHelp"></p>
      </div>
    </aside>

    <section class="edit-col" id="gwEditor" hidden>
      <div class="ga-status" id="gwStatus"></div>

      <div class="row">
        <label class="f grow">Название
          <input type="text" id="fTitleRu" maxlength="120" placeholder="Розыгрыш 5 Magnet" />
        </label>
        <label class="f grow">Название по-английски <span class="muted">(пусто — переведём сами)</span>
          <input type="text" id="fTitleEn" maxlength="120" placeholder="Giveaway: 5 Magnet" />
        </label>
      </div>

      <div class="f">Баннер <span class="muted">(свой у каждого розыгрыша: 1280×720, 16:9; важный текст — не ближе ~60 px к углам, они скругляются; показывается целиком, без обрезки)</span>
        <div class="ga-image">
          <img class="ga-image-prev" id="fImgPrev" alt="" hidden />
          <div class="slot-row">
            <label class="btn small file-btn">Загрузить<input type="file" id="fImgFile" accept="image/png,image/jpeg,image/webp" /></label>
            <button class="btn small" id="fImgDel" type="button" hidden>Убрать</button>
            <span class="muted small" id="fImgNote">PNG, JPG или WebP до 6 МБ. Без баннера шапку соберут иконки призов.</span>
          </div>
        </div>
      </div>

      <div class="f">Описание
        <div class="ga-toolbar" id="fTools" role="toolbar" aria-label="Форматирование">
          <button class="btn small" type="button" data-cmd="bold" title="Жирный (Ctrl+B)"><b>Ж</b></button>
          <button class="btn small" type="button" data-cmd="italic" title="Курсив (Ctrl+I)"><i>К</i></button>
          <button class="btn small" type="button" data-cmd="underline" title="Подчёркнутый (Ctrl+U)"><u>Ч</u></button>
          <button class="btn small" type="button" data-cmd="strikeThrough" title="Зачёркнутый"><s>З</s></button>
          <button class="btn small" type="button" data-cmd="link" title="Ссылка">🔗 Ссылка</button>
          <button class="btn small" type="button" data-cmd="spoiler" title="Спойлер — текст скрыт, пока не нажмут">Спойлер</button>
          <button class="btn small" type="button" data-cmd="insertUnorderedList" title="Список">• Список</button>
          <button class="btn small" type="button" data-cmd="insertOrderedList" title="Нумерованный список">1. Список</button>
          <button class="btn small" type="button" data-cmd="quote" title="Цитата">❝ Цитата</button>
          <button class="btn small" type="button" data-cmd="removeFormat" title="Убрать оформление">Очистить</button>
        </div>
        <div class="ga-rte" id="fBody" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Описание розыгрыша"></div>
        <span class="muted small">Английский переводится сам при сохранении. Вставленный текст приходит без чужого оформления.</span>
      </div>

      <div class="f">Призы <span class="muted">(разыгрываются по порядку: сначала первый; иконка — из предметов тирлиста)</span>
        <div class="ga-prizes" id="fPrizes"></div>
        <div><button class="btn small" id="fPrizeAdd" type="button">＋ Приз</button></div>
      </div>

      <p class="ga-live-note" id="fCondsLive" hidden>Розыгрыш идёт, но условия можно менять. Снимите галочку канала или уберите своё условие (✕) и нажмите «Сохранить» — бот сразу перестанет его требовать: при участии, при выборе победителей и при отписке. Новое условие, наоборот, при выборе победителей проверится и у тех, кто уже участвует.</p>

      <div class="f">Условия: подписка на каналы <span class="muted">(ничего не отмечено — условий нет, хватит входа и бота)</span>
        <div class="pages" id="fChannels"></div>
      </div>

      <div class="f">Свои условия <span class="muted">(текст, надпись на кнопке и ссылка — YouTube, TikTok, что угодно; пустая надпись — «Подписаться»; нажатие на условие открывает ссылку. Ссылка в канал, где бот — админ: выберите «Проверять: канал» в списке у условия, и бот проверит подписку, как у отмеченных выше. Без канала бот условие не проверяет, участвовать можно и без него)</span>
        <div class="ga-conds" id="fConds"></div>
        <div><button class="btn small" id="fCondAdd" type="button">＋ Условие</button></div>
      </div>

      <div class="f">Когда итоги
        <div class="pages ga-endby" role="radiogroup">
          <label class="page-chk"><input type="radio" name="fEndBy" id="fEndTime" value="time" /> По времени</label>
          <label class="page-chk"><input type="radio" name="fEndBy" id="fEndEntries" value="entries" /> По числу участников</label>
        </div>
        <span class="muted small" id="fEndLegacy" hidden>Сейчас у розыгрыша и дата конца, и лимит участников. После сохранения останется только выбранное здесь.</span>
      </div>

      <div class="f" id="fEndTimeBox">
        <div class="row">
          <label class="f">Конец (МСК)
            <input type="datetime-local" id="fEnds" />
          </label>
          <div class="f ga-quick">Быстро
            <div class="slot-row">
              <button class="btn small" type="button" data-plus="1">+1 день</button>
              <button class="btn small" type="button" data-plus="2">+2 дня</button>
              <button class="btn small" type="button" data-plus="3">+3 дня</button>
              <button class="btn small" type="button" data-plus="7">+7 дней</button>
            </div>
          </div>
        </div>
        <span class="muted small">В это время приём закроется и итоги подведутся сами. Участвовать может сколько угодно человек.</span>
      </div>

      <label class="f" id="fEndEntriesBox">Число участников
        <input type="number" id="fMax" class="ga-max" min="1" max="1000000" step="1" inputmode="numeric" placeholder="например, 1000" />
        <span class="muted small">Когда участников станет столько, приём закроется и итоги подведутся сразу. Срока нет — розыгрыш идёт, пока не наберётся. Не меньше числа призов.</span>
      </label>

      <div class="f">Проверка
        <div class="pages">
          <label class="page-chk"><input type="checkbox" id="fTest" /> Тестовый — посетители его не видят, бот о старте никому не пишет</label>
        </div>
        <span class="muted small">Видит только тот, кто открыл maknemy.com/giveaways?test (кнопка «Тест ↗» вверху) — браузер запомнит, и окно на страницах тоже покажет тестовый. Выйти — /giveaways?test=0. Галочка меняется только до публикации.</span>
      </div>

      <div class="row ga-actions">
        <button class="btn primary" id="aSave" type="button">Сохранить</button>
        <button class="btn primary" id="aPublish" type="button">Опубликовать</button>
        <button class="btn" id="aEnd" type="button">Завершить досрочно</button>
        <button class="btn primary" id="aDraw" type="button">Подвести итоги сейчас</button>
        <span class="ga-gap"></span>
        <button class="btn danger" id="aDelete" type="button">Удалить</button>
      </div>
      <p class="ga-msg" id="gwMsg" role="status" aria-live="polite"></p>

      <section class="ga-people" id="gwPeople" hidden>
        <div class="list-head">Участники <span class="muted" id="gwCount"></span></div>
        <div class="ga-table-wrap">
          <table class="ga-table">
            <thead><tr><th>Игрок</th><th>Telegram</th><th>Когда</th><th>Откуда</th><th>Приз</th><th></th></tr></thead>
            <tbody id="gwPeopleBody"></tbody>
          </table>
        </div>
      </section>

      <section class="ga-report" id="gwReport" hidden>
        <div class="list-head">Для рекламодателя</div>
        <p class="ga-sources" id="gwSources"></p>
        <div class="ga-report-channels" id="gwReportChannels"></div>
      </section>
    </section>

    <div class="ga-picker" id="gwPicker" hidden role="dialog" aria-modal="true" aria-labelledby="gwPickTitle">
      <div class="ga-picker-box">
        <div class="ga-picker-head">
          <span class="list-head" id="gwPickTitle">Иконка приза</span>
          <input type="search" id="gwPickSearch" placeholder="Поиск: Magnet, Dragon…" aria-label="Поиск предмета" />
          <button class="btn small" id="gwPickNone" type="button">Без иконки</button>
          <button class="btn small" id="gwPickClose" type="button" aria-label="Закрыть">✕</button>
        </div>
        <div class="ga-picker-grid" id="gwPickGrid"></div>
        <p class="muted small" id="gwPickEmpty" hidden>Ничего не нашлось.</p>
      </div>
    </div>

    <section class="ga-preview" id="gwPreview" hidden>
      <div class="list-head">Как увидят на сайте</div>
      <div class="ga-preview-box" id="gwPrevBox"></div>
    </section>
  </main>
</div>

<script src="/js/i18n.js?v=80"></script>
<script src="/js/news-blocks.js?v=3"></script>
<script src="/js/giveaway.js?v=10"></script>
<script src="/js/giveaway-admin.js?v=9"></script>
</body>
</html>
HTML;
