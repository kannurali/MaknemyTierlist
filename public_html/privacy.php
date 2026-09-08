<?php
// /privacy — политика конфиденциальности.
//
// Документ описывает то, что сайт делает НА САМОМ ДЕЛЕ. Каждый пункт можно
// проверить по коду, и при правке кода правится и он:
//   вход через Roblox      → api/roblox_callback.php, api/lib/roblox_oauth.php
//   что храним о вошедшем  → таблица users в schema.sql
//   сессионная кука        → start_site_session() в api/_bootstrap.php
//   лимиты по IP           → rate_limit_allow()/throttle_* там же
//   Яндекс Метрика         → api/lib/metrika.php (вебвизор включён)
//   localStorage           → js/app.js, js/promo.js, js/topbar.js
//
// Ссылку на эту страницу требует Roblox при публикации OAuth-приложения
// (Privacy Policy URL), поэтому английская версия обязательна и идёт следом
// за русской на той же странице.
require_once __DIR__ . '/api/_bootstrap.php';
require_once __DIR__ . '/api/lib/legal_page.php';

legal_page_open(
    'privacy',
    'Политика конфиденциальности | Maknemy Tier List',
    'Какие данные собирает maknemy.com, зачем, сколько хранит и как их удалить.'
);
?>
<?php legal_section_open('ru'); ?>
      <h1>Политика конфиденциальности</h1>
      <p class="lg-date">Действует с 8 сентября 2026 года</p>

      <p>Сайт <b>maknemy.com</b> — любительский проект о трейдах в игре Blox Fruits:
      список ценности предметов, калькулятор сделок и новости. Сайт не связан с
      Roblox Corporation и с разработчиками Blox Fruits.</p>

      <h2>1. Какие данные мы получаем</h2>

      <h3>Вход через Roblox</h3>
      <p>Вход на сайт делается кнопкой «Войти через Roblox». Логин, пароль и
      двухфакторный код вы вводите <b>на сайте roblox.com</b> — мы их не видим и
      не можем увидеть. Сайт не хранит паролей вообще.</p>
      <p>После вашего согласия Roblox передаёт нам только публичную часть
      профиля, и мы сохраняем её целиком:</p>
      <ul>
        <li>числовой идентификатор аккаунта Roblox;</li>
        <li>ник (username);</li>
        <li>отображаемое имя (display name);</li>
        <li>ссылку на картинку аватара;</li>
        <li>дату первого входа и дату последнего входа.</li>
      </ul>
      <p>Электронную почту, возраст, список друзей, инвентарь и историю трейдов
      мы не запрашиваем — эти разрешения у приложения не включены. Токены
      доступа Roblox нигде не сохраняются: они живут один запрос за профилем в
      момент входа и уничтожаются.</p>

      <h3>Технические данные</h3>
      <ul>
        <li><b>IP-адрес.</b> Обрабатывается, чтобы ограничить частоту запросов —
        защита от накрутки лайков и перебора пароля админки. На диске лежит не
        сам адрес, а его хеш, во временном файле, не дольше часа. Кроме того,
        IP попадает в служебные журналы веб-сервера у хостинг-провайдера.</li>
        <li><b>Сессионная cookie</b> (<code>PHPSESSID</code>). Техническая,
        нужна только чтобы сайт помнил, что вы вошли. Помечена HttpOnly и
        Secure, стирается при выходе.</li>
        <li><b>Данные в браузере</b> (localStorage). Выбранный язык интерфейса,
        отметка о поставленном лайке, кеш рекламных материалов и отметка о
        показанном рекламном окне. Всё это остаётся в вашем браузере и на наш
        сервер не отправляется.</li>
      </ul>

      <h3>Статистика посещений</h3>
      <p>На сайте стоит счётчик <b>Яндекс Метрики</b> (номер 111127188). Он
      собирает обезличенную статистику: посещённые страницы, источник перехода,
      тип устройства и браузера, приблизительный регион. У счётчика включены
      <b>Вебвизор</b> (запись действий на странице — движения мыши, клики,
      прокрутка), карта кликов и отслеживание переходов по ссылкам.</p>
      <p>Эти данные обрабатывает компания «Яндекс» по своим правилам и своей
      политике конфиденциальности. Отключить сбор можно расширением-блокировщиком
      или настройками браузера — на работу сайта это не влияет.</p>

      <h2>2. Зачем нам эти данные</h2>
      <ul>
        <li>показать ваш аватар и ник в шапке сайта, чтобы вы видели, что вошли;</li>
        <li>отличать обычного посетителя от администратора;</li>
        <li>защищаться от накрутки счётчиков и подбора пароля;</li>
        <li>понимать, какими разделами пользуются, а какими нет.</li>
      </ul>
      <p>Мы не показываем рекламу «по интересам», не строим профили и не
      используем данные аккаунта для рассылок.</p>

      <h2>3. Кому мы передаём данные</h2>
      <p>Никому. Данные не продаются и не передаются третьим лицам, кроме двух
      неизбежных случаев: обмен с <b>Roblox</b> в момент входа и обезличенная
      статистика в <b>Яндекс Метрику</b>. Рекламодатели получают только сводные
      цифры показов и переходов — без данных о конкретном посетителе.</p>
      <p>Мы обязаны раскрыть данные, если этого потребует закон.</p>

      <h2>4. Сколько мы храним</h2>
      <ul>
        <li>Запись о вошедшем — пока вы не попросите её удалить.</li>
        <li>Хеш IP для ограничения частоты — до одного часа.</li>
        <li>Журналы веб-сервера — по правилам хостинг-провайдера.</li>
        <li>Статистика Метрики — по правилам «Яндекса».</li>
      </ul>

      <h2>5. Как отозвать доступ и удалить свои данные</h2>
      <ol>
        <li>Отозвать доступ приложения можно в настройках аккаунта Roblox, в
        разделе подключённых приложений. После этого вход на сайт перестанет
        работать до нового согласия.</li>
        <li>Чтобы мы стёрли строку с вашим ником и аватаром из базы, напишите
        нам (контакты ниже) и укажите свой ник в Roblox. Удалим в течение
        30 дней.</li>
      </ol>

      <h2>6. Дети</h2>
      <p>Мы не спрашиваем возраст и не собираем ничего сверх публичного профиля
      Roblox, который вы сами разрешили передать. Если вы родитель и считаете,
      что данные ребёнка попали к нам, напишите — удалим.</p>

      <h2>7. Изменения</h2>
      <p>Мы можем менять эту политику. Дата вверху страницы всегда показывает
      действующую редакцию.</p>

      <h2>8. Контакты</h2>
      <p>Телеграм проекта: <a href="https://t.me/theMaknemy" target="_blank" rel="noopener">t.me/theMaknemy</a></p>
<?php legal_section_close(); ?>

<?php legal_section_open('en'); ?>
      <h1>Privacy Policy</h1>
      <p class="lg-date">Effective 8 September 2026</p>

      <p><b>maknemy.com</b> is a fan-made project about trading in the game Blox
      Fruits: an item value list, a trade calculator and news. The site is not
      affiliated with Roblox Corporation or with the developers of Blox Fruits.</p>

      <h2>1. What data we receive</h2>

      <h3>Logging in with Roblox</h3>
      <p>You log in with the “Log in with Roblox” button. Your login, password and
      two-factor code are entered <b>on roblox.com</b> — we never see them and
      cannot see them. This site stores no passwords at all.</p>
      <p>After you approve the request, Roblox gives us only the public part of
      your profile, and we store all of it:</p>
      <ul>
        <li>your numeric Roblox account ID;</li>
        <li>your username;</li>
        <li>your display name;</li>
        <li>the URL of your avatar image;</li>
        <li>the date of your first and most recent login.</li>
      </ul>
      <p>We do not request your email, age, friends, inventory or trade history —
      those permissions are not enabled for our application. Roblox access tokens
      are never stored: a token lives for the single request that fetches your
      profile at login and is then discarded.</p>

      <h3>Technical data</h3>
      <ul>
        <li><b>IP address.</b> Used to rate-limit requests — protection against
        vote stuffing and password guessing on the admin panel. What is written
        to disk is a hash of the address, in a temporary file, for no longer than
        an hour. Your IP also appears in the web server logs kept by our hosting
        provider.</li>
        <li><b>Session cookie</b> (<code>PHPSESSID</code>). Strictly technical:
        it only lets the site remember that you are logged in. It is HttpOnly and
        Secure, and it is cleared when you log out.</li>
        <li><b>Browser storage</b> (localStorage). Your interface language, a
        marker that you left a like, a cache of advertising material and a marker
        that the ad popup was shown. All of it stays in your browser and is never
        sent to our server.</li>
      </ul>

      <h3>Visit statistics</h3>
      <p>The site runs a <b>Yandex Metrica</b> counter (id 111127188). It collects
      anonymous statistics: pages visited, referrer, device and browser type,
      approximate region. <b>Session Replay (Webvisor)</b>, click maps and outbound
      link tracking are enabled, which means mouse movement, clicks and scrolling
      on the page are recorded.</p>
      <p>This data is processed by Yandex under its own rules and privacy policy.
      You can block the counter with a content blocker or browser settings; the
      site works either way.</p>

      <h2>2. Why we need it</h2>
      <ul>
        <li>to show your avatar and nickname in the site header so you can see
        that you are logged in;</li>
        <li>to tell a regular visitor from an administrator;</li>
        <li>to defend against counter stuffing and password guessing;</li>
        <li>to know which sections are used and which are not.</li>
      </ul>
      <p>We do not run interest-based advertising, we do not build profiles, and
      we do not use account data for mailings.</p>

      <h2>3. Who we share data with</h2>
      <p>Nobody. We do not sell data and do not pass it to third parties, apart
      from two unavoidable cases: the exchange with <b>Roblox</b> at login, and
      anonymous statistics sent to <b>Yandex Metrica</b>. Advertisers receive only
      aggregate impression and click numbers — nothing about an individual
      visitor.</p>
      <p>We will disclose data if required to by law.</p>

      <h2>4. How long we keep it</h2>
      <ul>
        <li>Your login record — until you ask us to delete it.</li>
        <li>The IP hash used for rate limiting — up to one hour.</li>
        <li>Web server logs — per our hosting provider's rules.</li>
        <li>Metrica statistics — per Yandex's rules.</li>
      </ul>

      <h2>5. Revoking access and deleting your data</h2>
      <ol>
        <li>You can revoke the application's access in your Roblox account
        settings, in the connected applications section. Logging in here will
        then stop working until you approve it again.</li>
        <li>To have the row with your nickname and avatar erased from our
        database, contact us (below) and tell us your Roblox username. We will
        delete it within 30 days.</li>
      </ol>

      <h2>6. Children</h2>
      <p>We do not ask for your age and collect nothing beyond the public Roblox
      profile you chose to share. If you are a parent and believe your child's
      data reached us, contact us and we will delete it.</p>

      <h2>7. Changes</h2>
      <p>We may update this policy. The date at the top of the page always shows
      the current version.</p>

      <h2>8. Contact</h2>
      <p>Project Telegram: <a href="https://t.me/theMaknemy" target="_blank" rel="noopener">t.me/theMaknemy</a></p>
<?php legal_section_close(); ?>
<?php legal_page_close(); ?>
