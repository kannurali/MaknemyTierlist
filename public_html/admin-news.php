<?php
// Лента новостей в режиме редактирования — /admin/news.
//
// Как и /admin для тирлиста: разметку НЕ дублируем, а переотдаём news.html,
// подставив в неё панель админа и модалку редактора. Разница с тирлистом в
// том, что там тулбар перемешан с публичными кнопками (фильтры, язык) и
// вынуть его нельзя, а здесь редактор — самостоятельный кусок. Поэтому с
// публичной /news он убран совсем: посетитель ленты не качает ни модалку на
// восемь полей, ни кнопку «Добавить».
//
// Адрес /admin/news лежит на глубине 1, но относительные пути внутри
// news.html («css/base.css», «api/news.php») считаются от адреса документа и
// уехали бы в /admin/. Поэтому все они переписываются на абсолютные ниже.
require_once __DIR__ . '/api/lib/admin_page.php';
admin_page_guard('Новости');

$html = @file_get_contents(__DIR__ . '/news.html');
if ($html === false) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'news.html не читается';
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
    '<link rel="stylesheet" href="/css/admin-shell.css?v=1" />' . "
</head>",
    $html
);
$html = preg_replace('~<body>~', "<body>
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
        <div class="field">
          <label for="neBodyRu" data-i18n="news.fieldBodyRu">Текст (RU)</label>
          <textarea id="neBodyRu" rows="8" maxlength="20000"></textarea>
        </div>
        <div class="field">
          <label for="neBodyEn" data-i18n="news.fieldBodyEn">Текст (EN, необязательно)</label>
          <textarea id="neBodyEn" rows="6" maxlength="20000"></textarea>
        </div>

        <div class="field">
          <label data-i18n="news.fieldCategory">Категория</label>
          <div class="ne-cat-seg" id="neCat"></div>
        </div>

        <div class="field">
          <label for="neDate" data-i18n="news.fieldDate">Дата</label>
          <input type="date" id="neDate" class="ne-date" required />
        </div>

        <div class="field">
          <label data-i18n="news.fieldImage">Картинка</label>
          <div class="tb-group">
            <button class="btn small" type="button" id="neImagePick" data-i18n="news.imageUpload">🖼 Загрузить</button>
            <button class="btn small ghost" type="button" id="neImageClear" data-i18n="news.imageClear">Убрать</button>
          </div>
          <input type="file" id="neImageFile" accept="image/*" hidden />

          <!-- Кроп-редактор: появляется между выбором файла и его заливкой
               (см. обработчик #neImageFile в news-page.js). Скрыт, пока файл
               не выбран/не декодировался — открытие существующего поста без
               смены картинки его не показывает, показывать там нечего. -->
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
          <label for="nePct" data-i18n="news.fieldImageWidth">Ширина картинки</label>
          <div class="ne-pct-row">
            <input type="range" id="nePct" min="10" max="100" step="5" value="100" />
            <output for="nePct" id="nePctValue">100%</output>
          </div>
        </div>

        <div class="field">
          <label data-i18n="news.fieldImageAlign">Выравнивание картинки</label>
          <div class="ne-cat-seg" id="neAlign"></div>
        </div>

        <div class="field">
          <label class="ne-wrap-field">
            <input type="checkbox" id="neWrap" class="ne-wrap-check" />
            <span data-i18n="news.fieldImageWrap">Обтекание текстом</span>
          </label>
        </div>

        <div class="field">
          <label data-i18n="news.previewHeading">Предпросмотр карточки</label>
          <div class="ne-preview-card" id="nePreviewCard"></div>
        </div>

        <p class="ne-error" id="neError" role="alert"></p>
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

header('Content-Type: text/html; charset=utf-8');
echo $html;
