<?php
// /terms — условия использования.
//
// Второй документ, который Roblox требует при публикации OAuth-приложения
// (Terms of Service URL). Устроен так же, как /privacy: русский блок, следом
// английский, общая оболочка из api/lib/legal_page.php.
//
// Главное, ради чего документ вообще нужен помимо галочки у Roblox: сказать
// прямо, что цены на сайте — оценка команды, а не гарантия, и что сайт не
// участвует в сделках. Тирлист читают подростки, которые по нему меняются
// предметами; неверно понятая «цена с сайта» — это чужие потерянные фрукты и
// претензии к нам.
require_once __DIR__ . '/api/_bootstrap.php';
require_once __DIR__ . '/api/lib/legal_page.php';

legal_page_open(
    'terms',
    'Условия использования | Maknemy Tier List',
    'Правила пользования maknemy.com: статус цен, вход через Roblox, реклама, ответственность.'
);
?>
<?php legal_section_open('ru'); ?>
      <h1>Условия использования</h1>
      <p class="lg-date">Действует с 8 сентября 2026 года</p>

      <p>Пользуясь сайтом <b>maknemy.com</b>, вы соглашаетесь с этими условиями.
      Не согласны — просто не пользуйтесь сайтом.</p>

      <h2>1. Что это за сайт</h2>
      <p>Любительский проект о трейдах в игре Blox Fruits: список ценности
      предметов, калькулятор сделок, новости. Сайт <b>не связан</b> с Roblox
      Corporation и с разработчиками Blox Fruits, не одобрен ими и не действует
      от их имени. Roblox, Blox Fruits и другие названия и логотипы принадлежат
      их владельцам и используются для того, чтобы обозначить, о чём сайт.</p>

      <h2>2. Цены — это мнение, а не гарантия</h2>
      <p>Ценность предметов на сайте — <b>оценка команды проекта</b>, собранная
      из наблюдений за рынком. Это не официальные данные игры, не курс и не
      обещание. Рынок меняется, оценка может устареть или оказаться неверной.</p>
      <p>Все сделки вы совершаете <b>на свой страх и риск</b>. Сайт не является
      участником, посредником или гарантом сделок между игроками, не проводит
      обмены и не возмещает потери. Калькулятор считает по тем же оценочным
      числам и ничего не гарантирует.</p>

      <h2>3. Вход через Roblox</h2>
      <p>Вход нужен только для того, чтобы показать ваш ник и аватар. Мы
      <b>никогда</b> не спрашиваем пароль от Roblox: пароль вводится
      исключительно на roblox.com. Если какой-либо сайт, включая якобы наш,
      просит пароль Roblox — это мошенничество.</p>
      <p>Мы не запрашиваем доступ к инвентарю, друзьям и сделкам, и не можем
      что-либо сделать с вашим аккаунтом. Какие данные мы храним — написано в
      <a href="/privacy">политике конфиденциальности</a>.</p>

      <h2>4. Чего делать нельзя</h2>
      <ul>
        <li>ломать сайт, мешать его работе, обходить ограничения и накручивать
        счётчики;</li>
        <li>массово выкачивать содержимое автоматическими средствами;</li>
        <li>выдавать себя за команду проекта;</li>
        <li>использовать материалы сайта для обмана других игроков.</li>
      </ul>
      <p>За нарушение мы можем закрыть доступ и удалить учётную запись без
      предупреждения.</p>

      <h2>5. Реклама и розыгрыши</h2>
      <p>На сайте есть платные рекламные места; они помечены. За содержание
      рекламы и за то, что предлагает рекламодатель, отвечает сам рекламодатель.
      Переходя по рекламной ссылке, вы покидаете наш сайт.</p>
      <p>Условия розыгрышей объявляются отдельно в каждом розыгрыше. Розыгрыши
      проводит команда проекта, Roblox к ним отношения не имеет.</p>

      <h2>6. Содержимое сайта</h2>
      <p>Оформление, тексты и подборки сайта принадлежат команде проекта.
      Копировать их целиком для своего сайта нельзя. Ссылаться на нас и
      цитировать с указанием источника — можно и приветствуется.</p>

      <h2>7. Ответственность</h2>
      <p>Сайт работает «как есть». Мы не обещаем бесперебойной работы,
      сохранности данных и точности цен, и не отвечаем за убытки, возникшие
      из-за пользования сайтом или доверия к опубликованным оценкам.</p>

      <h2>8. Изменения</h2>
      <p>Мы можем менять эти условия. Дата вверху страницы всегда показывает
      действующую редакцию.</p>

      <h2>9. Контакты</h2>
      <p>Телеграм проекта: <a href="https://t.me/theMaknemy" target="_blank" rel="noopener">t.me/theMaknemy</a></p>
<?php legal_section_close(); ?>

<?php legal_section_open('en'); ?>
      <h1>Terms of Service</h1>
      <p class="lg-date">Effective 8 September 2026</p>

      <p>By using <b>maknemy.com</b> you agree to these terms. If you do not agree,
      simply do not use the site.</p>

      <h2>1. What this site is</h2>
      <p>A fan-made project about trading in the game Blox Fruits: an item value
      list, a trade calculator and news. The site is <b>not affiliated</b> with
      Roblox Corporation or the developers of Blox Fruits, is not endorsed by them
      and does not act on their behalf. Roblox, Blox Fruits and other names and
      logos belong to their respective owners and are used to describe what the
      site is about.</p>

      <h2>2. Values are an opinion, not a guarantee</h2>
      <p>Item values on this site are <b>our team's estimate</b>, based on watching
      the trading market. They are not official game data, not an exchange rate and
      not a promise. The market moves, and an estimate can be outdated or simply
      wrong.</p>
      <p>Every trade you make is <b>at your own risk</b>. The site is not a party,
      an intermediary or a guarantor in trades between players, does not conduct
      trades and does not compensate losses. The calculator works from the same
      estimated numbers and guarantees nothing.</p>

      <h2>3. Logging in with Roblox</h2>
      <p>Logging in only serves to show your nickname and avatar. We
      <b>never</b> ask for your Roblox password: it is entered on roblox.com and
      nowhere else. If any site — including one claiming to be ours — asks for
      your Roblox password, it is a scam.</p>
      <p>We request no access to your inventory, friends or trades, and we cannot
      do anything to your account. What we store is described in our
      <a href="/privacy">privacy policy</a>.</p>

      <h2>4. What you may not do</h2>
      <ul>
        <li>break the site, disrupt its operation, bypass limits or stuff counters;</li>
        <li>bulk-scrape its content with automated tools;</li>
        <li>impersonate the project team;</li>
        <li>use material from the site to defraud other players.</li>
      </ul>
      <p>We may cut off access and delete an account for any of the above, without
      warning.</p>

      <h2>5. Advertising and giveaways</h2>
      <p>The site carries paid advertising slots, and they are marked as such. The
      advertiser is responsible for the content of an ad and for whatever it
      offers. Following an ad link takes you off our site.</p>
      <p>Giveaway rules are announced with each giveaway. Giveaways are run by the
      project team; Roblox has nothing to do with them.</p>

      <h2>6. Site content</h2>
      <p>The design, texts and compilations on the site belong to the project team
      and may not be republished wholesale as your own site. Linking to us and
      quoting with attribution is fine and welcome.</p>

      <h2>7. Liability</h2>
      <p>The site is provided “as is”. We do not promise uninterrupted operation,
      data retention or accurate values, and we are not liable for losses arising
      from using the site or relying on the published estimates.</p>

      <h2>8. Changes</h2>
      <p>We may update these terms. The date at the top of the page always shows
      the current version.</p>

      <h2>9. Contact</h2>
      <p>Project Telegram: <a href="https://t.me/theMaknemy" target="_blank" rel="noopener">t.me/theMaknemy</a></p>
<?php legal_section_close(); ?>
<?php legal_page_close(); ?>
