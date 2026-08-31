/* ============================================================
   Страница новостей. Загружает ленту, рисует карточки, фильтрует.
   Редактор админа живёт в отдельном файле js/news-editor.js: его
   подключает только admin-news.php, и читатель ленты его не качает.
   Связь между файлами — window.NEWSPAGE в самом низу этого файла.
   ============================================================ */
(() => {
  "use strict";

  const $ = sel => document.querySelector(sel);
  const feedEl = $("#feed");
  const stateEl = $("#newsState");
  const filtersEl = $("#newsFilters");
  const noticeEl = $("#newsNotice");

  // Тот же ключ, что и на тирлисте (app.js, LANG_KEY) — иначе выбор языка
  // не переносился бы между страницами.
  const LANG_KEY = "nexus-lang-v1";

  // id поста из /news/<id> — news.php кладёт его сюда только когда такой
  // пост реально существует (иначе сервер уже ответил 404, см.
  // news_post_by_id() в news.php), поэтому здесь достаточно голой числовой
  // проверки, без своей валидации "существует ли".
  const LINKED_POST_ID = (() => {
    const n = Number(window.NX_LINKED_POST_ID);
    return Number.isInteger(n) && n > 0 ? n : null;
  })();
  // Проскроллить и сфокусировать карточку нужно один раз — на первой отрисовке
  // после загрузки, а не при каждом render() (смена фильтра/языка тоже зовёт
  // render() и не должна силой возвращать читателя к посту, от которого он
  // уже мог уйти).
  let linkedPostScrolled = false;

  // Адреса эндпоинтов — от корня. Эта же разметка отдаётся на /admin/news,
  // а он лежит на глубине 1: документ-относительный "api/news.php" оттуда
  // уехал бы в /admin/api/news.php. app.js ходит по абсолютным по той же
  // причине.

  let posts = [];
  let activeCat = "all";
  // Ставит js/news-editor.js через window.NEWSPAGE (см. низ файла). Сам
  // редактор живёт в другом файле и грузится только на /admin/news, поэтому
  // ✎/✕ на карточке зовут не функции, а хуки: без редактора их просто некому
  // поставить, и кнопки в этом случае не рисуются вовсе (isAdmin ложен).
  let langHook = null;
  let editHook = null;
  let deleteHook = null;
  // Как в applyLang в app.js: localStorage бросает в приватном режиме Safari
  // (и в некоторых встроенных webview с отключённым хранилищем). Без try/catch
  // это исключение случилось бы прямо в теле IIFE до объявления load() ниже —
  // он бы не вызвался вообще, и посетитель получил бы пустую сцену без ленты,
  // без ошибки и без кнопки «Повторить».
  let lang = I18N.pickLang(
    (() => { try { return localStorage.getItem(LANG_KEY); } catch (_) { return null; } })(),
    navigator.language);

  const tx = key => I18N.t(key, lang);

  // ------------------------------------------------------------------------
  //  Лайк поста — тот же приём, что LIKE BUTTON в app.js (общий счётчик
  //  тирлиста), только лайков теперь много, по одному на пост: факт лайка
  //  запоминается в localStorage под id поста, счётчик хранится в БД
  //  (news.likes) и правится через POST /api/news_like.php.
  // ------------------------------------------------------------------------
  const NEWS_LIKED_KEY = "nexus-news-liked-v1";

  // Карта { "<id>": true } — только реально лайкнутые посты, а не полный
  // список постов со значением true/false: лента растёт, и хранить запись на
  // каждый когда-либо увиденный пост незачем.
  function readLikedMap() {
    try { return JSON.parse(localStorage.getItem(NEWS_LIKED_KEY) || "{}"); }
    catch (_) { return {}; } // приватный режим Safari / битый JSON
  }
  function writeLikedMap(map) {
    try { localStorage.setItem(NEWS_LIKED_KEY, JSON.stringify(map)); } catch (_) {}
  }
  function isPostLiked(id) { return !!readLikedMap()[id]; }
  function setPostLiked(id, v) {
    const map = readLikedMap();
    if (v) { map[id] = true; } else { delete map[id]; }
    writeLikedMap(map);
  }

  // Отправка на сервер — тот же контракт, что sendLike() в app.js:
  //   true  — записано;
  //   false — сервер отклонил (нужен откат UI);
  //   null  — сеть недоступна (оффлайн, оставляем оптимистичный счётчик).
  async function sendNewsLike(id, dir) {
    try {
      const r = await fetch("/api/news_like.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id, dir }),
      });
      return r.ok ? true : false;
    } catch (e) { /* оффлайн — считаем локальным успехом */ }
    return null;
  }

  // короткий «всплеск» сердечка при клике — тот же приём, что popLike() в app.js
  function popLikeHeart(heartEl) {
    heartEl.classList.remove("pop");
    // getBoundingClientRect, а не offsetWidth: сердце теперь <svg>, а у
    // SVG-элементов offsetWidth не существует, и перезапуск анимации
    // молча переставал работать.
    heartEl.getBoundingClientRect();
    heartEl.classList.add("pop");
  }

  function renderLikeButton(btn, heartEl, countEl, liked, likes) {
    btn.classList.toggle("liked", liked);
    btn.setAttribute("aria-pressed", liked ? "true" : "false");
    // Число лайков на круглой кнопке больше не помещается, поэтому оно
    // ушло в подсказку и в скрытую от глаз, но читаемую скринридером
    // подпись (countEl, см. .nw-like-count в news-design.css).
    btn.title = tx(liked ? "news.likeRemove" : "news.like") + " (" + likes + ")";
    countEl.textContent = String(likes);
  }

  // Карточка не хранит собственное состояние между рендерами (render()
  // каждый раз пересобирает feedEl.innerHTML с нуля, см. ниже) — счётчик
  // читается из post.likes (правится этой же функцией оптимистично, так что
  // смена языка/фильтра между кликом и ответом сервера не откатывает его на
  // старое значение), а признак "лайкнуто" — из localStorage.
  function toggleNewsLike(post, btn, heartEl, countEl) {
    const willLike = !isPostLiked(post.id);
    const dir = willLike ? 1 : -1;
    // Оптимистично обновляем UI; откатываем только на явный отказ сервера.
    setPostLiked(post.id, willLike);
    post.likes = Math.max(0, (post.likes || 0) + dir);
    renderLikeButton(btn, heartEl, countEl, willLike, post.likes);
    popLikeHeart(heartEl);

    sendNewsLike(post.id, dir).then(ok => {
      if (ok === false) { // запись не прошла — откат
        setPostLiked(post.id, !willLike);
        post.likes = Math.max(0, post.likes - dir);
        renderLikeButton(btn, heartEl, countEl, !willLike, post.likes);
      }
    });
  }

  function showState(key, withRetry) {
    feedEl.innerHTML = "";
    stateEl.hidden = false;
    stateEl.textContent = tx(key);
    if (withRetry) {
      const b = document.createElement("button");
      b.className = "btn small";
      b.textContent = tx("news.retry");
      b.addEventListener("click", load);
      stateEl.append(document.createElement("br"), b);
    }
  }

  // Карточка собирается узлами, а не строкой HTML. Текст поста приходит от
  // админа и кладётся через textContent — санитайзера здесь нет намеренно,
  // потому что вставлять нечего: innerHTML в этом файле не используется.
  //
  // withTools=false используется превью в редакторе (см. ниже): та же самая
  // функция строит и настоящую карточку в ленте, и то, что видит админ до
  // публикации, — чтобы превью не могло разойтись с реальным рендером, но при
  // этом не рисовало кнопки ✎/✕ поверх ещё не сохранённого поста.
  // Контур сердца снят из макета (icon_filled/Heart, нода 182:573) как
  // есть — поэтому и viewBox у него нестандартный, "-2 0 57.3 51.2".
  const HEART_PATH = "M 17.46 0 C 15.82 0 13.96 0.17 12.18 0.78 C 1.47 4.28 -1.98 15.72 1.05 25.15 L 1.07 25.19 L 1.08 25.23 C 2.75 29.91 5.43 34.17 8.93 37.67 L 8.95 37.69 L 8.97 37.71 C 13.96 42.49 19.38 46.65 25.26 50.27 L 26.64 51.12 L 28.03 50.29 C 33.92 46.75 39.45 42.47 44.37 37.73 L 44.39 37.71 L 44.4 37.7 C 47.93 34.19 50.62 29.9 52.26 25.21 L 52.27 25.17 L 52.29 25.13 C 55.26 15.73 51.83 4.27 41.19 0.82 C 39.45 0.25 37.65 0 35.89 0 C 31.94 -0 29.02 1.65 26.66 3.34 C 24.31 1.67 21.37 0 17.46 0 Z";

  // Изогнутая стрелка «поделиться»: сплошная заливка, шаг вправо с хвостом,
  // уходящим влево-вниз. В наборе макета она называется icon_filled/Send,
  // но компонент отдаёт пустой экспорт, поэтому контур нарисован здесь.
  const SHARE_PATH = "M13.4 4.3c0-1 1.2-1.5 1.9-.8l6.9 6.6c.5.5.5 1.3 0 1.8l-6.9 6.6c-.7.7-1.9.2-1.9-.8v-3.2c-4.6.1-8 1.6-10.3 4.6-.6.8-1.9.3-1.7-.7C2.7 11.2 6.9 7 13.4 6.2V4.3Z";

  function cardFor(post, withTools = true) {
    const card = document.createElement("article");
    card.className = "nw-card";
    card.dataset.id = String(post.id);

    const meta = document.createElement("div");
    meta.className = "nw-meta";

    const date = document.createElement("span");
    date.className = "nw-date";
    date.textContent = NEWS.formatDate(post.published_at);
    meta.append(date);

    const cat = NEWS.CATEGORIES.find(c => c.key === post.category);
    if (cat) {
      const badge = document.createElement("span");
      badge.className = "nw-cat " + cat.cls;
      badge.textContent = tx(cat.i18n);
      meta.append(badge);
    }
    card.append(meta);

    const picked = NEWS.pickLang(post, lang);

    // Блочный пост рисуется блоками, легаси-пост — абзацами. Проверка идёт
    // через validateDoc, а не по одному лишь наличию body_json: строка в базе
    // могла пережить формат, а рисовать полуразобранный документ — худший из
    // вариантов. Не прошло проверку — падаем на плоский текст, который сервер
    // всё равно вывел в body_ru (см. handle_news_save в api/news_save.php).
    const blockDoc = post.body_json ? NEWSBLOCKS.validateDoc(post.body_json) : null;
    const asBlocks = !!(blockDoc && blockDoc.ok);

    const h = document.createElement("h2");
    h.textContent = picked.title;
    card.append(h);

    // У блочного поста картинки живут в теле, а image_url — производная
    // колонка для превью ссылки (см. handle_news_save). Рисовать её ещё и
    // сверху значило бы показать первую картинку дважды.
    if (post.image_url && !asBlocks) {
      const img = document.createElement("img");

      // Ширина — число, которое реально прошло валидацию (10..100), поэтому
      // её можно класть в inline style напрямую: это не пользовательский
      // текст, а посчитанное значение. Мусор/выход за границы (пост,
      // сохранённый до появления этого поля, или что-то незнакомое) — те же
      // 100, что и на сервере при отсутствующем image_pct.
      const pctNum = Number(post.image_pct);
      const pct = Number.isFinite(pctNum) && pctNum >= 10 && pctNum <= 100 ? pctNum : 100;
      img.style.width = pct + "%";

      // Неизвестное/отсутствующее выравнивание — center, как и на сервере
      // (validate_news_post трактует пустое так же).
      const align = NEWS.ALIGNS.find(a => a.key === post.image_align) || NEWS.ALIGNS[1];

      // center + обтекание не имеет смысла: у float нет «по центру» — он
      // умеет только «к левому» или «к правому» краю. Раз админ явно выбрал
      // центр, обтекание в этом случае молча выключается и картинка остаётся
      // блочной (как при wrap=false) — это безопаснее, чем самовольно
      // прижать её к стороне, которую никто не выбирал.
      const wrap = !!post.image_wrap && align.key !== "center";

      img.className = "nw-image " + (wrap
        ? (align.key === "left" ? "nw-img-float-left" : "nw-img-float-right")
        : "nw-img-" + align.key);

      img.src = post.image_url;
      img.alt = picked.title;
      img.loading = "lazy";
      img.decoding = "async";
      // width/height — не литеральный размер отображения (тем управляет CSS:
      // .nw-image{height:auto} и inline style выше), а подсказка браузеру для
      // резервирования места под картинку, пока байты ещё не пришли — иначе
      // текст под картинкой скачет при догрузке. Пост без картинки и пост,
      // сохранённый до появления этих колонок (image_width/image_height —
      // null), не дают атрибутов вовсе, а не 0×0 — 0 обнулил бы
      // зарезервированную высоту, а не оставил бы её неизвестной.
      if (post.image_width && post.image_height) {
        img.width = post.image_width;
        img.height = post.image_height;
      }
      card.append(img);
    }

    const body = document.createElement("div");
    body.className = "nw-body";
    if (asBlocks) {
      body.append(NEWSBLOCKS.renderBlocks(document, blockDoc.blocks, lang));
    } else {
      for (const para of NEWS.toParagraphs(picked.body)) {
        const p = document.createElement("p");
        p.textContent = para;
        body.append(p);
      }
    }
    card.append(body);

    // Лайк — виден всем посетителям (не только админу, в отличие от .nw-tools
    // ниже) и живёт вне её кластера инструментов: своя обёртка .nw-footer, а
    // не .nw-tools, — чтобы сердечко не оказалось внутри той же группы, что
    // и ✎/✕, и не покрывалось общим правилом «показывать только в режиме
    // редактирования» (.nw-editing .nw-card .nw-tools в news.css).
    const footer = document.createElement("div");
    footer.className = "nw-footer";
    const likeBtn = document.createElement("button");
    likeBtn.type = "button";
    likeBtn.className = "nw-like";
    // Сердце — контур из макета (icon_filled/Heart), а не эмодзи: по
    // редизайну кнопка стала круглой с белым знаком внутри, а эмодзи
    // рисуется системным шрифтом и в разных ОС выглядит по-разному.
    // Состояние «лайкнуто» показывает класс на кнопке (см.
    // renderLikeButton), поэтому текст внутрь больше не пишется.
    const likeHeart = document.createElementNS("http://www.w3.org/2000/svg", "svg");
    likeHeart.setAttribute("class", "nw-like-heart");
    likeHeart.setAttribute("viewBox", "-2 0 57.3 51.2");
    likeHeart.setAttribute("aria-hidden", "true");
    const heartPath = document.createElementNS("http://www.w3.org/2000/svg", "path");
    heartPath.setAttribute("d", HEART_PATH);
    heartPath.setAttribute("fill", "currentColor");
    heartPath.setAttribute("fill-rule", "evenodd");
    likeHeart.append(heartPath);
    const likeCount = document.createElement("span");
    likeCount.className = "nw-like-count";
    renderLikeButton(likeBtn, likeHeart, likeCount, isPostLiked(post.id), post.likes || 0);
    // Пост без настоящего id — черновик ещё не сохранённой новости в превью
    // редактора (buildPreviewPost() ставит id: 0). Лайкать нечего: серверный
    // эндпоинт получил бы id=0 и корректно отклонил бы его как «пост не
    // найден», но кнопка честнее сразу не отвечать на клик, чем гонять запрос
    // с заранее известным результатом.
    if (post.id > 0) {
      likeBtn.addEventListener("click", () => toggleNewsLike(post, likeBtn, likeHeart, likeCount));
    } else {
      likeBtn.disabled = true;
    }
    likeBtn.append(likeHeart, likeCount);
    footer.append(likeBtn);

    // Копирование ссылки на пост — рядом с лайком и у КАЖДОГО посетителя.
    // Раньше кнопка жила в .nw-tools вместе с ✎/✕, то есть существовала
    // только у админа: постоянные ссылки на посты были, а взять их читателю
    // было неоткуда, кроме как собрать адрес /news/<id> руками.
    // Тот же guard по id, что и у лайка выше: у черновика в превью редактора
    // id === 0, и ссылка вела бы на несуществующий /news/0.
    if (post.id > 0) {
      const copy = document.createElement("button");
      copy.type = "button";
      copy.className = "nw-copy";
      // Иконка из макета (icon_filled/Send — изогнутая стрелка) вместо
      // прежней эмодзи-скрепки с подписью: по редизайну кнопка круглая и
      // стоит рядом с сердцем, подписи в ней нет места. Рисуется тем же
      // контуром, что иконки в шапке: обводка currentColor, скруглённые
      // концы. Смысл кнопки при этом не изменился — она копирует ссылку
      // на пост, см. copyPostLink() ниже.
      const copyIcon = document.createElementNS("http://www.w3.org/2000/svg", "svg");
      copyIcon.setAttribute("viewBox", "0 0 24 24");
      copyIcon.setAttribute("aria-hidden", "true");
      copyIcon.setAttribute("class", "nw-copy-icon");
      const copyPath = document.createElementNS("http://www.w3.org/2000/svg", "path");
      copyPath.setAttribute("d", SHARE_PATH);
      copyPath.setAttribute("fill", "currentColor");
      copyIcon.append(copyPath);
      copy.append(copyIcon);
      // Подпись живёт только в title и aria-label: на круглой кнопке текста
      // нет, и без них назначение иконки было бы неочевидным.
      copy.title = tx("news.copyLink");
      copy.setAttribute("aria-label", tx("news.copyLink"));
      copy.addEventListener("click", () => copyPostLink(post, copy));
      footer.append(copy);
    }
    card.append(footer);

    if (withTools && isAdmin) {
      const tools = document.createElement("div");
      tools.className = "nw-tools";
      const edit = document.createElement("button");
      edit.type = "button";
      edit.textContent = "✎";
      edit.title = tx("news.edit");
      edit.addEventListener("click", () => { if (editHook) { editHook(post); } });
      const del = document.createElement("button");
      del.type = "button";
      del.className = "danger";
      del.textContent = "✕";
      del.title = tx("news.delete");
      del.addEventListener("click", () => { if (deleteHook) { deleteHook(post); } });
      tools.append(edit, del);
      card.append(tools);
    }

    // Спойлер и раскрывающаяся цитата открываются по клику и с клавиатуры.
    // Обработчик один на карточку, а не на каждый спан: спанов в длинном
    // посте десятки, а поведение у них одно.
    if (asBlocks) {
      const reveal = target => {
        const el = target.closest && target.closest(".nw-spoiler, .nw-quote-collapsible");
        if (!el) { return false; }
        el.classList.add("is-open");
        return true;
      };
      card.addEventListener("click", ev => { reveal(ev.target); });
      card.addEventListener("keydown", ev => {
        if (ev.key !== "Enter" && ev.key !== " ") { return; }
        if (reveal(ev.target)) { ev.preventDefault(); }
      });
    }

    return card;
  }

  function render() {
    const visible = activeCat === "all"
      ? posts
      : posts.filter(p => p.category === activeCat);

    // "В этой категории пока ничего нет" не подходит, когда категория вообще
    // не выбрана (activeCat === "all") — это день первый ленты, когда постов
    // нет в принципе, а не "в этом фильтре пусто". Разные строки на разные
    // причины пустоты.
    if (!visible.length) {
      showState(activeCat === "all" ? "news.emptyAll" : "news.empty", false);
      focusLinkedPost();
      return;
    }

    stateEl.hidden = true;
    feedEl.innerHTML = "";
    for (const post of visible) { feedEl.append(cardFor(post)); }
    focusLinkedPost();
  }

  // /news/<id>: подсвечивает и (один раз, при первой отрисовке) скроллит к
  // карточке, на которую вела ссылка. Зовётся из render() каждый раз —
  // render() полностью пересобирает feedEl.innerHTML, поэтому класс
  // подсветки нужно накладывать заново на каждой перерисовке (смена языка,
  // смена фильтра), а не один раз при загрузке.
  function focusLinkedPost() {
    if (!LINKED_POST_ID || !noticeEl) { return; }

    const inFeed = posts.some(p => p.id === LINKED_POST_ID);
    const card = feedEl.querySelector('[data-id="' + LINKED_POST_ID + '"]');

    if (card) {
      noticeEl.hidden = true;
      card.classList.add("nw-linked");
      if (!linkedPostScrolled) {
        linkedPostScrolled = true;
        const reduceMotion = window.matchMedia
          && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
        card.scrollIntoView({ behavior: reduceMotion ? "auto" : "smooth", block: "start" });
        // tabindex делает карточку фокусируемой программно (сама она не
        // интерактивный элемент) — скринридер объявляет её содержимое сразу
        // после перехода по ссылке, а не оставляет читателя гадать, куда его
        // проскроллило молча.
        card.setAttribute("tabindex", "-1");
        card.focus({ preventScroll: true });
      }
      return;
    }

    // Пост существует (иначе news.php уже ответил бы 404, и
    // window.NX_LINKED_POST_ID вообще не был бы выставлен), но не попал ни в
    // последние 50 из api/news.php, ни, тем более, в текущую карточку —
    // либо он старше 50-го, либо скрыт активным фильтром категории. Фильтр
    // категории — осознанный выбор читателя ПРЯМО СЕЙЧАС, поясняться нечему;
    // случай "постов вообще нет" уже виден по #newsState — отдельное
    // сообщение здесь было бы повтором той же мысли. Настоящая, стоящая
    // упоминания причина ровно одна: пост есть, но за пределами ленты.
    if (inFeed || posts.length === 0) {
      noticeEl.hidden = true;
    } else {
      noticeEl.hidden = false;
      noticeEl.textContent = tx("news.linkedPostMissing");
    }
  }

  function renderFilters() {
    // Контейнера может не быть: по редизайну строка фильтров с разметки
    // убрана. Молча выходим, а не падаем — весь остальной код ленты от
    // фильтров не зависит, и вернуть их можно, добавив обратно
    // <div id="newsFilters"> в news.php.
    if (!filtersEl) return;
    filtersEl.innerHTML = "";
    const label = document.createElement("span");
    label.className = "tb-label";
    label.textContent = tx("news.filterLabel");
    filtersEl.append(label);

    const mk = (key, text, isAll) => {
      const b = document.createElement("button");
      b.type = "button";
      b.className = "chip" + (isAll ? " all" : "") + (activeCat === key ? " active" : "");
      b.textContent = text;
      b.addEventListener("click", () => { activeCat = key; renderFilters(); render(); });
      return b;
    };
    for (const c of NEWS.CATEGORIES) { filtersEl.append(mk(c.key, tx(c.i18n), false)); }
    filtersEl.append(mk("all", tx("news.all"), true));
  }

  async function load() {
    showState("news.loading", false);
    try {
      const r = await fetch("/api/news.php", { cache: "no-store" });
      if (!r.ok) { throw new Error("http " + r.status); }
      const data = await r.json();
      posts = Array.isArray(data.posts) ? data.posts : [];
      renderFilters();
      render();
    } catch (e) {
      console.warn("не удалось загрузить ленту", e);
      showState("news.error", true);
    }
  }

  // Переключатель языка: тот же ключ в localStorage, что и на тирлисте,
  // поэтому выбор переносится между страницами.
  function applyLang(next) {
    lang = next;
    try { localStorage.setItem(LANG_KEY, next); } catch (_) { /* приватный режим */ }
    document.documentElement.lang = next;
    for (const node of document.querySelectorAll("[data-i18n]")) {
      node.textContent = I18N.t(node.dataset.i18n, lang);
    }
    // aria-label тоже переводимый текст (см. applyLang в app.js) — без этого
    // переключатель языка и панель фильтров остаются подписаны на языке
    // загрузки страницы.
    for (const node of document.querySelectorAll("[data-i18n-label]")) {
      node.setAttribute("aria-label", I18N.t(node.dataset.i18nLabel, lang));
    }
    // title — как data-i18n-title в app.js: рамка кроп-редактора (#neCropFrame)
    // документирует свои клавиши (стрелки/Shift+стрелки) и в title, и в
    // aria-label одной и той же строкой news.cropFrameLabel.
    for (const node of document.querySelectorAll("[data-i18n-title]")) {
      node.title = I18N.t(node.dataset.i18nTitle, lang);
    }
    for (const b of document.querySelectorAll("#langSwitch .chip")) {
      const on = b.dataset.lang === lang;
      b.classList.toggle("active", on);
      b.setAttribute("aria-pressed", String(on));
    }
    renderFilters();
    render();
    // Превью редактора зовёт cardFor() тем же путём, что и лента, поэтому
    // переключение языка обязано поменять и его — иначе оставленный на RU
    // текст в превью разошёлся бы с тем, что реально покажется на странице.
    // На публичной ленте хука нет: там и превью нет.
    if (langHook) { langHook(); }
  }

  // ---------- Админ ----------
  // Разметку редактора вставляет только admin-news.php (/admin/news), он же
  // ставит этот флаг. На публичной ленте ни того, ни другого нет, и роль у
  // сервера здесь не спрашивают вовсе — раньше это был лишний запрос с
  // каждого захода ради ответа «нет» для всех, кроме одного человека.
  // Роль известна синхронно, поэтому ✎/✕ рисуются с первого же render(),
  // без второго прохода по уже показанным карточкам.
  const isAdmin = window.NX_ADMIN_PAGE === true;


  // Живой (не hidden — см. .nw-sr-only в news.css) регион для скринридеров:
  // единственный текстовый отклик на копирование ссылки, который не зависит
  // от того, видит ли читатель title-подсказку на самой кнопке. Создаётся при
  // инициализации ленты — раньше это делал wireAdmin(), пока кнопка
  // копирования существовала только у админа.
  // Именно ЗАРАНЕЕ, а не лениво при первом клике: aria-live объявляет только
  // те изменения, которые произошли в уже существующем живом регионе, и узел,
  // созданный и заполненный в одном тике, скринридер промолчит.
  let copyStatusEl = null;

  // Абсолютная ссылка на пост + копирование в буфер с видимым откликом на
  // самой кнопке. navigator.clipboard.writeText требует секьюр-контекста и
  // явного разрешения (в некоторых embedded-webview его вовсе нет или он
  // отклоняется пользователем) — на этот случай запасной путь через скрытый
  // textarea + document.execCommand("copy"), единственный API, который
  // работает синхронно из обработчика клика без него.
  async function copyPostLink(post, btn) {
    const url = "https://maknemytierlist.site/news/" + post.id;
    let ok = false;
    try {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        await navigator.clipboard.writeText(url);
        ok = true;
      }
    } catch (e) {
      ok = false; // отклонено браузером/пользователем — пробуем запасной путь
    }
    if (!ok) { ok = copyViaFallback(url); }
    showCopyFeedback(btn, ok);
  }

  function copyViaFallback(text) {
    const ta = document.createElement("textarea");
    ta.value = text;
    // Вне видимой области, но НЕ display:none/hidden — Safari игнорирует
    // execCommand("copy") на элементе, которого нет в раскладке.
    ta.style.position = "fixed";
    ta.style.top = "-1000px";
    ta.style.left = "-1000px";
    ta.setAttribute("readonly", "");
    document.body.append(ta);
    ta.focus();
    ta.select();
    let ok = false;
    try { ok = document.execCommand("copy"); } catch (e) { ok = false; }
    ta.remove();
    return ok;
  }

  let copyFeedbackTimer = null;
  function showCopyFeedback(btn, ok) {
    if (copyStatusEl) { copyStatusEl.textContent = tx(ok ? "news.copyLinkDone" : "news.copyLinkFailed"); }
    if (!btn) { return; }
    clearTimeout(copyFeedbackTimer);
    btn.classList.remove("nw-copy-ok", "nw-copy-fail");
    btn.classList.add(ok ? "nw-copy-ok" : "nw-copy-fail");
    btn.title = tx(ok ? "news.copyLinkDone" : "news.copyLinkFailed");
    copyFeedbackTimer = setTimeout(() => {
      btn.classList.remove("nw-copy-ok", "nw-copy-fail");
      btn.title = tx("news.copyLink");
    }, 2000);
  }


  for (const b of document.querySelectorAll("#langSwitch .chip")) {
    b.addEventListener("click", () => applyLang(b.dataset.lang));
  }

  copyStatusEl = document.createElement("div");
  copyStatusEl.className = "nw-sr-only";
  copyStatusEl.setAttribute("aria-live", "polite");
  document.body.append(copyStatusEl);

  // Защита контента: гость не выделяет текст постов, не копирует их через
  // Ctrl+C и не утаскивает картинки карточек. Тот же модуль, что и на тирлисте
  // (js/protect.js), поэтому две страницы не могут разойтись в том, что именно
  // закрыто. Админу защиты нет вовсе: в редакторе нужно выделять и вставлять
  // текст поста, а рамка кропа тянется мышью.
  //
  // Кнопка «Ссылка» у каждого поста продолжает работать и у гостя: её
  // основной путь — navigator.clipboard, который события copy не порождает, а
  // запасной (execCommand поверх скрытого textarea, см. copyViaFallback) проходит
  // через исключение для полей ввода в protect.js. Копируется адрес поста, а не
  // его текст, — делиться ссылкой мы как раз хотим.
  //
  // Роль здесь известна синхронно (NX_ADMIN_PAGE), но модуль всё равно берёт
  // функцию — её ждёт тирлист, где роль приезжает ответом API позже.
  // ============================================================
  //  Рекламные борта по бокам ленты
  //
  //  Полосатые панели из макета — это не декор, а рекламные места. Данные
  //  и правила отбора берутся из той же системы, что борта на тирлисте:
  //  документ /api/promo.php и модуль js/promo.js (слот "rail"). Своей
  //  логики показа здесь нет намеренно — иначе две страницы разошлись бы
  //  в том, какая кампания сейчас крутится.
  //
  //  Пока слот не куплен, борта скрыты: в разметке у них стоит hidden, и
  //  снимается он только под реальный креатив. Полосатая панель из макета
  //  на живом сайте читается как поломка вёрстки, а не как свободное место,
  //  и так же ведут себя борта тирлиста.
  // ============================================================
  const PROMO_API = "/api/promo.php";

  function fillNewsRail(el, camp) {
    const promo = window.PROMO;
    const cre = promo && camp ? promo.creativeFor(camp, "rail") : null;
    if (!cre || !cre.src) return false;

    el.innerHTML = "";
    el.classList.add("has-ad");
    // Колонку показываем только теперь, когда картинка реально нашлась:
    // борт без креатива — пустая белая панель во всю высоту экрана.
    if (el.parentElement) el.parentElement.hidden = false;

    const img = document.createElement("img");
    img.src = cre.src;
    img.alt = tx("ad.imageAlt");
    img.loading = "lazy";
    img.decoding = "async";
    img.draggable = false;
    el.append(img);

    const chip = document.createElement("span");
    chip.className = "ptn-chip";
    chip.textContent = tx("ad.chip");
    el.append(chip);

    // Маркировка рекламы обязательна по закону, если рекламодатель её
    // прислал: без erid борт показывать можно, а вот выкидывать
    // присланный идентификатор — нельзя.
    if (camp.erid) {
      const erid = document.createElement("span");
      erid.className = "ptn-erid";
      erid.textContent = "erid: " + camp.erid;
      el.append(erid);
    }

    const url = promo.safeHref(camp.href);
    el.classList.toggle("has-link", !!url);
    if (url) {
      const open = () => window.open(url, "_blank", "noopener");
      el.onclick = open;
      el.tabIndex = 0;
      el.setAttribute("role", "link");
      el.onkeydown = e => {
        if (e.key === "Enter" || e.key === " ") { e.preventDefault(); open(); }
      };
    }
    return true;
  }

  function renderNewsRails() {
    const promo = window.PROMO;
    const left = document.getElementById("newsRailL");
    const right = document.getElementById("newsRailR");
    if (!promo || !left || !right) return;

    fetch(PROMO_API, { cache: "no-store" })
      .then(r => (r.ok ? r.json() : null))
      .then(doc => {
        if (!doc) return;
        const list = promo.eligible(promo.normalizeDoc(doc), "rail", Date.now());
        if (!list.length) return;              // не куплено — борта не показываем
        fillNewsRail(left, list[0]);
        // Один рекламодатель занимает оба борта: одинокий борт с одной
        // стороны ленты выглядит перекосом вёрстки, а не размещением.
        fillNewsRail(right, list[1] || list[0]);
      })
      // Реклама не должна ронять ленту: не пришёл документ — борта просто
      // остаются скрытыми, лента работает как обычно.
      .catch(() => {});
  }

  NX_PROTECT.applyClass(isAdmin);
  NX_PROTECT.install(() => isAdmin);

  applyLang(lang);
  load();
  renderNewsRails();

  // Шов для редактора (js/news-editor.js, грузится только на /admin/news).
  // Отдаётся ровно пять вещей, а не весь модуль: редактору нужно нарисовать
  // превью карточки ТЕМ ЖЕ кодом, что рисует ленту, перезагрузить ленту после
  // сохранения, знать язык и роль — и получить сигнал о смене языка. Больше
  // ничего; всё остальное состояние ленты остаётся закрытым.
  window.NEWSPAGE = {
    cardFor: cardFor,
    reload: load,
    getLang: () => lang,
    isAdmin: () => isAdmin,
    onLang: fn => { langHook = typeof fn === "function" ? fn : null; },
    onEdit: fn => { editHook = typeof fn === "function" ? fn : null; },
    onDelete: fn => { deleteHook = typeof fn === "function" ? fn : null; }
  };
})();
