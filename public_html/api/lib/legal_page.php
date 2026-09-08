<?php
// Оболочка правовых страниц — /privacy и /terms.
//
// Почему функция, а не пятая и шестая копии разметки страницы: общий хром на
// сайте действительно продублирован (home/index/news/calculator), и за
// расхождением копий следит tests/topbar_test.php. Но там четыре страницы с
// разным содержимым <head>, рекламой и i18n, а здесь два документа-близнеца,
// отличающиеся только текстом. Копия ради копии добавила бы ещё два места,
// где шапка может разъехаться, и ни одного выигрыша.
//
// Шапка здесь урезанная: логотип и две пилюли назад на сайт. Полная навигация
// с переключателем языка, рекламными местами и профилем правовому документу не
// нужна — это конечная страница, на которую приходят по ссылке из подвала или
// из карточки приложения Roblox.
//
// Язык: оба сразу, русский блок и следом английский. Переключатель i18n сюда
// не тянем — текст юридический, он не должен зависеть от того, что лежит в
// localStorage, и ревьюер Roblox (англоязычный) обязан увидеть английскую
// версию без единого клика.

require_once __DIR__ . '/metrika.php';

/**
 * Начало документа: <head>, фон, шапка и открытая <main>.
 *
 * @param string $slug        'privacy' | 'terms' — из него собирается canonical
 * @param string $title       <title> и og:title
 * @param string $description meta description и og:description
 */
function legal_page_open(string $slug, string $title, string $description): void {
    $url  = 'https://maknemy.com/' . $slug;
    $t    = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $d    = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');
    $u    = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    // Тот же Cache-Control, что у остальных страниц редизайна: файл несёт
    // номера версий ?v= для css/js, и закешированная копия прибила бы
    // посетителя к старому коду.
    header('Cache-Control: no-cache, must-revalidate');
    echo <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="color-scheme" content="dark" />
<base href="/" />

<title>{$t}</title>
<meta name="description" content="{$d}" />
<link rel="canonical" href="{$u}" />
<meta name="robots" content="index, follow" />

<meta property="og:type" content="website" />
<meta property="og:site_name" content="Maknemy Tier List" />
<meta property="og:locale" content="ru_RU" />
<meta property="og:url" content="{$u}" />
<meta property="og:title" content="{$t}" />
<meta property="og:description" content="{$d}" />

<link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48" />
<link rel="icon" type="image/png" href="/assets/favicon.png?v=2" sizes="256x256" />
<link rel="apple-touch-icon" href="/apple-touch-icon.png" />

<link rel="stylesheet" href="css/base.css?v=10" />
<link rel="stylesheet" href="css/topbar.css?v=11" />
<link rel="stylesheet" href="css/design-page.css?v=32" />
<link rel="stylesheet" href="css/legal.css?v=2" />
HTML;
    echo metrika_counter_html();
    echo <<<HTML
</head>
<body>

  <!-- Шапка: логотип и дорога назад. Ни языкового переключателя, ни профиля —
       см. комментарий в начале api/lib/legal_page.php. -->
  <header class="mk-top lg-top">
    <a class="mk-top-brand" href="/">
      <img class="mk-top-mark" src="assets/design/logo-mk-square.png" alt="" aria-hidden="true" />
      <img class="mk-top-word" src="assets/design/wordmark.svg" alt="MAKNEMY" />
    </a>
    <nav class="mk-top-bar" aria-label="Разделы сайта">
      <ul class="mk-nav">
        <!-- Иконки — те же, что в основной шапке сайта, и они обязательны: ниже
             761px topbar.css прячет .mk-pill-text, и пилюля без значка
             превращается в пустой синий кружок. -->
        <li><a class="mk-pill" href="/"><svg viewBox="0 0 19 19" fill="none" aria-hidden="true"><path d="M18.05 16.0302V8.423C18.05 7.48807 17.644 6.60551 16.9498 6.03152L11.833 1.80094C10.4608 0.666372 8.53926 0.666371 7.16704 1.80094L2.05028 6.03152C1.35606 6.60551 0.950013 7.48807 0.950013 8.423V16.0302C0.950013 17.1457 1.80067 18.05 2.85001 18.05H4.75001C5.79936 18.05 6.65001 17.1994 6.65001 16.15V13.0006C6.65001 11.8851 7.50067 10.9808 8.55002 10.9808H10.45C11.4994 10.9808 12.35 11.8851 12.35 13.0006V16.15C12.35 17.1994 13.2007 18.05 14.25 18.05H16.15C17.1994 18.05 18.05 17.1457 18.05 16.0302Z" stroke="currentColor" stroke-width="1.81101"/></svg><span class="mk-pill-text">Главная</span></a></li>
        <li><a class="mk-pill" href="/tierlist"><svg viewBox="0 0 19 19" fill="none" aria-hidden="true"><path d="M8.57627 3.7533C8.57627 3.22702 8.14799 2.79425 7.62582 2.85987C6.45486 3.00701 5.32947 3.42467 4.341 4.08515C3.08735 4.9228 2.11026 6.1134 1.53327 7.50637C0.95628 8.89935 0.805314 10.4321 1.09946 11.9109C1.39361 13.3897 2.11965 14.748 3.18579 15.8142C4.25193 16.8803 5.61027 17.6063 7.08904 17.9005C8.56781 18.1946 10.1006 18.0437 11.4936 17.4667C12.8866 16.8897 14.0771 15.9126 14.9148 14.659C15.5753 13.6705 15.9929 12.5451 16.1401 11.3741C16.2057 10.852 15.7729 10.4237 15.2466 10.4237H9.52918C9.0029 10.4237 8.57627 9.99705 8.57627 9.47077V3.7533Z" stroke="currentColor" stroke-width="1.82067"/><path d="M11.435 1.84748C11.435 1.3212 11.8638 0.887589 12.3847 0.962518C12.934 1.04153 13.4726 1.18898 13.9876 1.40232C14.7969 1.73754 15.5323 2.22887 16.1517 2.84828C16.7711 3.46768 17.2624 4.20302 17.5976 5.0123C17.811 5.52735 17.9584 6.06592 18.0374 6.61527C18.1124 7.13618 17.6787 7.56495 17.1525 7.56495L11.5303 7.56495C11.4777 7.56495 11.435 7.52228 11.435 7.46965V1.84748Z" stroke="currentColor" stroke-width="1.82067"/></svg><span class="mk-pill-text">Тирлист</span></a></li>
      </ul>
    </nav>
  </header>

  <main class="lg">

HTML;
}

/** Конец документа: закрытая <main>, общий подвал сайта и </html>. */
function legal_page_close(): void {
    echo <<<HTML

  </main>

  <footer class="mk-foot">
    <img class="mk-foot-mark" src="assets/design/logo-mk-square.png" alt="MAKNEMY" />
    <ul class="mk-foot-roles">
      <li><span>автор</span><span class="mk-foot-nick">MKSVTN</span></li>
      <li><span>дизайнер</span><span class="mk-foot-nick">DANIKTOR</span></li>
      <li><span>аналитик</span><span class="mk-foot-nick">GLH</span></li>
      <li><span>помощник аналитика</span><span class="mk-foot-nick">активно ищем</span></li>
      <li><span>разработчик</span><span class="mk-foot-nick">The Fool</span></li>
    </ul>
    <p class="mk-foot-tagline">макнеми тирлист - гарантия успешных трейдов</p>
    <p class="mk-foot-legal">
      <a href="/privacy">Политика конфиденциальности</a>
      <a href="/terms">Условия использования</a>
    </p>
  </footer>

</body>
</html>

HTML;
}

/**
 * Заголовок языкового блока. Отдельной функцией, потому что у английского
 * блока обязателен lang="en" — без него скринридер прочитает английский текст
 * русскими правилами, а поисковик посчитает страницу одноязычной.
 */
function legal_section_open(string $lang): void {
    $attr = $lang === 'en' ? ' lang="en"' : '';
    echo "\n    <section class=\"lg-doc\"{$attr}>\n";
}

function legal_section_close(): void {
    echo "    </section>\n";
}
