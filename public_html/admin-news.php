<?php
// Лента новостей в режиме редактирования — /admin/news.
//
// Как и /admin для тирлиста: разметку НЕ дублируем, а исполняем news.php
// (см. admin_render_public_page() в api/lib/admin_page.php) и подставляем в
// его вывод панель админа и модалку редактора. Разница с тирлистом в том, что
// там тулбар перемешан с публичными кнопками (фильтры, язык) и вынуть его
// нельзя, а здесь редактор — самостоятельный кусок. Поэтому с публичной /news
// он убран совсем: посетитель ленты не качает ни модалку на восемь полей, ни
// кнопку «Добавить». С переезда со статичного news.html на news.php (лента
// сама считает og:title/description/image из живых данных, см.
// news_og_data() в news.php) простой file_get_contents() больше не годится —
// он вернул бы исходный PHP-код, а не отрендеренную страницу.
//
// Адрес /admin/news лежит на глубине 1, но относительные пути внутри
// news.php («css/base.css», «api/news.php») считаются от адреса документа и
// уехали бы в /admin/. Поэтому все они переписываются на абсолютные ниже.
require_once __DIR__ . '/api/lib/admin_page.php';
admin_page_guard('Новости');

$html = admin_render_public_page(__DIR__ . '/news.php');
if ($html === null) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'news.php не рендерится';
    exit;
}

$html = preg_replace('~<title>.*?</title>~su', '<title>Новости — редактирование</title>', $html, 1);
$html = preg_replace(
    '~<meta\s+name="robots"[^>]*>~i',
    '<meta name="robots" content="noindex,nofollow" />',
    $html,
    1
);
// Канонический адрес и og:url ведут на публичную ленту — на служебной
// странице им делать нечего.
$html = preg_replace('~\s*<link rel="canonical"[^>]*>~i', '', $html, 1);

// Документ-относительные пути на глубине 1 указывали бы в /admin/.
$html = preg_replace('~(src|href)="(?!https?:|//|/|#|data:)~i', '$1="/', $html);

$html = str_replace(
    '</head>',
    '<link rel="stylesheet" href="/css/admin-shell.css?v=2" />' . "
</head>",
    $html
);
// Тег body ищем с атрибутами: лента объявляет <body class="nw-body">
// (по этому классу к экрану прибит фон, см. news-design.css), и точный
// поиск "<body>" молча промахивался — шапка админки не вставлялась,
// а панель открывалась без навигации между разделами.
$html = preg_replace('~<body[^>]*>~', "$0
" . admin_nav('news'), $html, 1);

// Панель с кнопкой «Добавить» — на своё место внутри сцены, над фильтрами.
$html = str_replace(
    '<!-- ADMIN-BAR -->',
    '<div class="nw-admin-bar" id="newsAdminBar" hidden></div>',
    $html
);

// Модалка редактора и флаг роли — перед скриптами: news-page.js читает
// NX_ADMIN_PAGE на старте и без него остаётся в режиме «только чтение».
$editor = <<<'HTML'
  <!-- ================= Редактор новости (для админа) ================= -->
  <div class="modal-backdrop" id="newsEditor" hidden>
    <div class="modal">
      <div class="modal-head">
        <h3 id="neHeading" data-i18n="news.modalNew">Новая новость</h3>
        <button class="btn small ghost" type="button" id="neClose" data-i18n-label="modal.close" aria-label="Закрыть">✕</button>
      </div>

      <div class="modal-body">
        <div class="field">
          <label for="neTitleRu" data-i18n="news.fieldTitleRu">Заголовок (RU)</label>
          <input type="text" id="neTitleRu" maxlength="200" />
        </div>
        <div class="field">
          <label for="neTitleEn" data-i18n="news.fieldTitleEn">Заголовок (EN, необязательно)</label>
          <input type="text" id="neTitleEn" maxlength="200" />
        </div>
        <!-- Тело поста — список блоков (абзац, цитата, список, код, картинка,
             альбом). Переключатель RU/EN меняет ТОЛЬКО текст: порядок блоков
             и картинки общие для обоих языков, см.
             docs/superpowers/specs/2026-08-29-news-block-editor-design.md. -->
        <div class="field">
          <div class="ne-body-head">
            <label data-i18n="news.fieldBody">Текст поста</label>
            <div class="ne-lang-seg" id="neLang" role="group" data-i18n-label="news.fieldBodyLang" aria-label="Язык текста">
              <button type="button" data-v="ru" class="active">RU</button>
              <button type="button" data-v="en">EN</button>
            </div>
          </div>
          <div class="ne-blocks" id="neBlocks"></div>
          <div class="ne-add" id="neAddRow">
            <button class="btn small ghost" type="button" data-add="p" data-i18n="news.blockP">Абзац</button>
            <button class="btn small ghost" type="button" data-add="quote" data-i18n="news.blockQuote">Цитата</button>
            <button class="btn small ghost" type="button" data-add="list" data-i18n="news.blockList">Список</button>
            <button class="btn small ghost" type="button" data-add="code" data-i18n="news.blockCode">Код</button>
            <button class="btn small ghost" type="button" data-add="image" data-i18n="news.blockImage">Картинка</button>
            <button class="btn small ghost" type="button" data-add="album" data-i18n="news.blockAlbum">Альбом</button>
            <span class="ne-count" id="neBlockCount"></span>
          </div>
        </div>

        <div class="field">
          <label data-i18n="news.fieldCategory">Категория</label>
          <div class="ne-cat-seg" id="neCat"></div>
        </div>

        <div class="field">
          <label for="neDate" data-i18n="news.fieldDate">Дата</label>
          <input type="date" id="neDate" class="ne-date" required />
        </div>

        <!-- Кроп-редактор: появляется между выбором файла в блоке-картинке
             (см. startCropFor() в news-editor.js) и его заливкой. Скрыт, пока
             файл не выбран или не декодировался. Своего поля выбора файла у
             редактора больше нет: <input type="file"> создаёт тот блок,
             который его и открыл (chooseFile()), — иначе на пост с десятком
             картинок пришлось бы держать десяток скрытых полей. -->
        <div class="field">
          <div class="ne-crop" id="neCrop" hidden>
            <label data-i18n="news.cropHeading">Кадрирование и зум</label>
            <div class="ne-crop-stage" id="neCropStage">
              <canvas class="ne-crop-canvas" id="neCropCanvas"></canvas>
              <div class="ne-crop-frame" id="neCropFrame" tabindex="0"
                   data-i18n-label="news.cropFrameLabel" data-i18n-title="news.cropFrameLabel"
                   aria-label="Рамка кадрирования" title="Рамка кадрирования">
                <div class="ne-crop-handle" data-corner="nw"></div>
                <div class="ne-crop-handle" data-corner="ne"></div>
                <div class="ne-crop-handle" data-corner="sw"></div>
                <div class="ne-crop-handle" data-corner="se"></div>
              </div>
            </div>
            <div class="ne-pct-row">
              <label for="neCropZoom" data-i18n="news.cropZoom">Зум</label>
              <input type="range" id="neCropZoom" min="100" max="400" step="1" value="100" />
              <output for="neCropZoom" id="neCropZoomValue">100%</output>
            </div>
            <p class="ne-crop-dims" id="neCropDims" aria-live="polite"></p>
            <div class="tb-group ne-crop-actions">
              <button class="btn small ghost" type="button" id="neCropSkip" data-i18n="news.cropSkip">Без кадрирования</button>
              <button class="btn small primary" type="button" id="neCropConfirm" data-i18n="news.cropConfirm">Обрезать</button>
            </div>
          </div>
        </div>

        <div class="field">
          <label data-i18n="news.previewHeading">Предпросмотр карточки</label>
          <div class="ne-preview-card" id="nePreviewCard"></div>
        </div>

        <p class="ne-error" id="neError" role="alert"></p>
      </div>


      <!-- Панель форматирования всплывает над выделением. Живёт ВНУТРИ
           .modal-backdrop (тот position: fixed и перекрыл бы её собой) и
           позиционируется тоже fixed — по координатам выделения в окне, без
           арифметики с прокруткой: и модалка, и панель считают от одного и
           того же вьюпорта. -->
      <div class="ne-fmt" id="neFmt" hidden role="toolbar" data-i18n-label="news.fmtToolbar" aria-label="Форматирование">
        <button type="button" data-fmt="b"  data-i18n-title="news.fmtBold"><b>Ж</b></button>
        <button type="button" data-fmt="i"  data-i18n-title="news.fmtItalic"><i>К</i></button>
        <button type="button" data-fmt="u"  data-i18n-title="news.fmtUnderline"><u>П</u></button>
        <button type="button" data-fmt="st" data-i18n-title="news.fmtStrike"><s>З</s></button>
        <button type="button" data-fmt="c"  data-i18n-title="news.fmtCode">&lt;/&gt;</button>
        <button type="button" data-fmt="sp" data-i18n-title="news.fmtSpoiler">▨</button>
        <button type="button" data-fmt="a"  data-i18n-title="news.fmtLink">🔗</button>
      </div>
      <div class="modal-foot">
        <button class="btn" type="button" id="neCancel" data-i18n="news.cancel">Отмена</button>
        <button class="btn primary" type="button" id="nePublish" data-i18n="news.publish">Опубликовать</button>
      </div>
    </div>
  </div>

HTML;

$html = str_replace(
    '  <script src="/js/i18n.js',
    $editor . "
  <script>window.NX_ADMIN_PAGE = true;</script>
  <script src=\"/js/i18n.js",
    $html
);

// Сам редактор — отдельным файлом и ТОЛЬКО здесь. На публичной /news его нет:
// читателю не нужны ни модалка на восемь полей, ни кроп-канвас. Подключается
// ПОСЛЕ news-page.js: тот в конце объявляет window.NEWSPAGE, из которого
// редактор берёт cardFor/reload/getLang — до объявления шва он бы не нашёл
// ничего и молча ничего не сделал.
$html = preg_replace(
    '~(<script src="/js/news-page\.js[^"]*"></script>)~',
    '$1' . "\n  " . '<script src="/js/news-editor.js?v=1"></script>',
    $html,
    1
);

header('Content-Type: text/html; charset=utf-8');
echo $html;
