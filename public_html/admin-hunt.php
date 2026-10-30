<?php
// Хэллоуинский ивент Spooky Tokens — /admin/hunt. Только admin_ids.
//
// Страница — каркас: форму сезона, сводку, итоги и участников наполняет
// js/hunt-admin.js по ответам /api/hunt_admin.php. Пути абсолютные:
// страница живёт на /admin/hunt, относительные уехали бы в /admin/css/.
require_once __DIR__ . '/api/lib/admin_page.php';
admin_page_guard();

header('Content-Type: text/html; charset=utf-8');
$nav = admin_nav('hunt');
echo <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="color-scheme" content="dark" />
<meta name="robots" content="noindex,nofollow" />
<title>Ивент — панель управления</title>
<link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48" />
<link rel="stylesheet" href="/css/admin-shell.css?v=4" />
<link rel="stylesheet" href="/css/promo-admin.css?v=4" />
<link rel="stylesheet" href="/css/hunt-admin.css?v=1" />
</head>
<body>
{$nav}

<div class="app" id="app">
  <header class="top">
    <div class="brand">ИВЕНТ<span>Spooky Tokens</span></div>
    <div class="top-actions">
      <a class="btn" href="/halloween" target="_blank" rel="noopener">Страница на сайте ↗</a>
      <span class="hint" id="haHint"></span>
    </div>
  </header>

  <p class="ha-alert" id="haAlert" hidden></p>

  <main class="ha-main">
    <section class="ha-card" id="haSeason">
      <div class="ha-head">
        <h2>Сезон</h2>
        <span class="ha-phase" id="haPhase"></span>
      </div>

      <div class="row">
        <label class="f">Статус
          <select id="fStatus">
            <option value="draft">Черновик — на сайте не виден</option>
            <option value="live">Опубликован</option>
          </select>
        </label>
        <label class="f">Первый день (по Москве)
          <input type="date" id="fStart" />
        </label>
        <div class="f grow">Семь дней ивента
          <span class="ha-note" id="fDays"></span>
        </div>
      </div>

      <div class="row">
        <label class="f">Билеты продаются до (МСК)
          <input type="datetime-local" id="fSales" />
        </label>
        <label class="f">Итоги (МСК, текст на странице)
          <input type="datetime-local" id="fDraw" />
        </label>
        <label class="f">Дней на подтверждение выигрыша
          <input type="number" id="fClaim" min="1" max="60" step="1" />
        </label>
      </div>

      <div class="row">
        <label class="f grow">Название
          <input type="text" id="fTitleRu" maxlength="120" />
        </label>
        <label class="f grow">Название по-английски
          <input type="text" id="fTitleEn" maxlength="120" />
        </label>
      </div>

      <div class="f">Награда за вход по дням
        <div class="ha-ints" id="fLogin"></div>
      </div>
      <div class="f">Награда за время на сайте каждый день
        <div class="ha-ints" id="fHours"></div>
      </div>
      <p class="ha-sum" id="fSum"></p>
      <p class="ha-note" id="fLocked" hidden>Ивент уже начался: дата начала и награды заперты — у людей собраны токены по этим числам, и цена билета зависит от них.</p>

      <div class="row">
        <label class="f grow">Титул за все 7 дней входа
          <input type="text" id="fTLoginRu" maxlength="40" />
        </label>
        <label class="f grow">по-английски
          <input type="text" id="fTLoginEn" maxlength="40" />
        </label>
      </div>
      <div class="row">
        <label class="f grow">Титул за все часы на сайте
          <input type="text" id="fTHoursRu" maxlength="40" />
        </label>
        <label class="f grow">по-английски
          <input type="text" id="fTHoursEn" maxlength="40" />
        </label>
      </div>

      <div class="f">Призы <span class="muted">(разыгрываются по порядку: сначала первый; один человек — один приз)</span>
        <div class="ha-prizes" id="fPrizes"></div>
        <div><button class="btn small" id="fPrizeAdd" type="button">＋ Приз</button></div>
        <datalist id="haItems"></datalist>
      </div>

      <div class="row">
        <label class="f grow">Правила
          <textarea id="fRulesRu" rows="5" maxlength="4000"></textarea>
        </label>
        <label class="f grow">Правила по-английски
          <textarea id="fRulesEn" rows="5" maxlength="4000"></textarea>
        </label>
      </div>

      <div class="row end">
        <button class="btn primary" id="haSave" type="button">Сохранить</button>
      </div>
    </section>

    <section class="ha-card" id="haStats" hidden>
      <div class="ha-head"><h2>Сводка</h2></div>
      <div class="ha-kpis" id="haKpis"></div>
    </section>

    <section class="ha-card" id="haDraw" hidden>
      <div class="ha-head">
        <h2>Итоги</h2>
        <button class="btn primary" id="haDrawBtn" type="button">Разыграть</button>
      </div>
      <p class="ha-note" id="haDrawNote"></p>
      <table class="ha-table" id="haWins"></table>
    </section>

    <section class="ha-card" id="haPeople" hidden>
      <div class="ha-head">
        <h2>Участники <span class="muted" id="haPeopleCount"></span></h2>
        <input type="search" id="haSearch" placeholder="Поиск по нику или id" aria-label="Поиск участника" />
      </div>
      <table class="ha-table" id="haPeopleTable"></table>
    </section>
  </main>
</div>

<script src="/js/hunt-admin.js?v=2"></script>
</body>
</html>
HTML;
