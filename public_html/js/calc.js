// Калькулятор трейдов: чистая логика без DOM — разбор значений, суммы,
// разница, вердикт, подсказка по спросу и кодирование ссылки для «поделиться».
//
// Файл не трогает DOM: его можно подключить <script>-ом в браузере и
// потребовать из node в тестах — тот же приём, что у js/news.js и js/tiers.js.
// DOM и обработчики событий живут отдельно, в js/calculator-page.js.
(function (root) {
  "use strict";

  // ==========================================================================
  //  Разбор значения предмета
  // ==========================================================================
  // value в API — строка ("45000"). По ТЗ все 113 значений в живых данных
  // парсятся чисто, но модуль обязан пережить и мусор: битую БД, испорченный
  // ?v=share из чужой ссылки, ручную правку в devtools. Строгий формат
  // отсекает то, что Number() пропустил бы молча: "12abc", "1,234", " ",
  // "Infinity", "0x10".
  //
  // Минус НЕ разрешён намеренно. Предмет не может стоить меньше нуля, а
  // отрицательное значение уходило бы в basis у computeTrade и переворачивало
  // знак diffPct: опечатка админа "-45000" давала «Вы в минусе» там, где
  // человек получает больше. Такое значение теперь считается мусором и даёт
  // 0 через itemValue(), как любое другое нечитаемое.
  var VALUE_RE = /^\d+(\.\d+)?$/;

  function parseValue(raw) {
    // Отрицательное отсекается в обеих ветках, а не только в регулярке:
    // число приходит сюда из тестов и из чужого кода, и «меньше нуля» там
    // так же бессмысленно, как в строке из БД.
    if (typeof raw === "number") {
      return Number.isFinite(raw) && raw >= 0 ? raw : null;
    }
    if (typeof raw !== "string") { return null; }
    var s = raw.trim();
    if (s === "" || !VALUE_RE.test(s)) { return null; }
    var n = Number(s);
    return Number.isFinite(n) ? n : null;
  }

  // Значение предмета для арифметики. Неразборчивое значение считается за 0,
  // а не роняет сумму всей стороны — один битый предмет не должен обнулять
  // весь калькулятор.
  function itemValue(item) {
    var v = item ? parseValue(item.value) : null;
    return v === null ? 0 : v;
  }

  // ==========================================================================
  //  Каталог предметов — плоский список из ответа api/tierlist.php
  // ==========================================================================
  function flattenTierlist(data) {
    var items = [];
    if (!data || !Array.isArray(data.tiers)) { return items; }
    for (var i = 0; i < data.tiers.length; i++) {
      var tier = data.tiers[i];
      if (!tier || !Array.isArray(tier.items)) { continue; }
      for (var j = 0; j < tier.items.length; j++) {
        var it = tier.items[j];
        if (it && typeof it === "object" && it.id) { items.push(it); }
      }
    }
    return items;
  }

  // id -> предмет. Объект без прототипа — id "constructor"/"toString" не
  // должен читаться как унаследованное свойство (тот же приём, что t() в
  // i18n.js использует hasOwnProperty).
  function buildCatalogIndex(items) {
    var idx = Object.create(null);
    (items || []).forEach(function (it) { if (it && it.id) { idx[it.id] = it; } });
    return idx;
  }

  // ==========================================================================
  //  Состояние одной стороны сделки: [{item, count}], без дубликатов по id
  // ==========================================================================
  // Доска — ровно 4 слота на сторону: сетка 2×2 из макета Figma
  // («калькулятор», node 127:303) и ровно столько же мест, сколько даёт
  // окно обмена в самой Blox Fruits. Слот — это строка entries, а не
  // единица count: один и тот же предмет, добавленный второй раз,
  // увеличивает count в своей же строке и нового слота не занимает.
  // Значит "мест не осталось" — это ровно entries.length === MAX_SLOTS.
  //
  // Слот вмещает ОДИН предмет: два одинаковых занимают два слота, а не одну
  // строку со счётчиком. Поэтому count у строки всегда единица, и складывать
  // его при подсчётах незачем. В токене ссылки count всё ещё разбирается —
  // но только чтобы развернуть старые ссылки вида "id:3" в три слота
  // (см. decodeSide).
  var MAX_SLOTS = 4;

  // Есть ли ещё свободный слот. Повтор уже занятого предмета больше не
  // бесплатный: второй экземпляр занимает свой слот, как любой другой
  // предмет, — поэтому item здесь ни на что не влияет и остался в подписи
  // только ради вызывающих (интерфейс зовёт проверку ПЕРЕД addToSide, чтобы
  // отличить «мест нет» от обычного добавления).
  function canAddToSide(entries, item, maxSlots) {
    var max = typeof maxSlots === "number" ? maxSlots : MAX_SLOTS;
    return (entries || []).length < max;
  }

  // Кладёт предмет в отдельный слот. count>1 — это count отдельных слотов
  // подряд, а не одна строка со счётчиком: столько, сколько поместится.
  // Лишнее молча отбрасывается — потолок слотов держит сам модуль (см. тот же
  // довод у decodeSide ниже).
  function addToSide(entries, item, count, maxSlots) {
    var max = typeof maxSlots === "number" ? maxSlots : MAX_SLOTS;
    var next = (entries || []).slice();
    if (!item) { return next; }
    var add = count > 0 ? Math.round(count) : 1;
    for (var i = 0; i < add && next.length < max; i++) {
      next.push({ item: item, count: 1 });
    }
    return next;
  }

  // Убирает ОДИН слот с этим предметом — первый попавшийся. Остальные его
  // копии остаются на доске: каждая из них теперь самостоятельный слот, и
  // снимать их скопом по нажатию на один — не то, чего ждёт человек.
  function removeOneFromSide(entries, id) {
    var next = [];
    var removed = false;
    (entries || []).forEach(function (e) {
      if (!removed && e.item && e.item.id === id) { removed = true; return; }
      next.push(e);
    });
    return next;
  }

  function clearSide() { return []; }

  // Слот — один предмет, поэтому сумма стороны это просто сумма её слотов.
  function sideTotal(entries) {
    return (entries || []).reduce(function (sum, e) {
      return sum + itemValue(e.item);
    }, 0);
  }

  // ==========================================================================
  //  Значок типа предмета — двухбуквенный код из легенды редизайна тирлиста
  // (index.php, section.legend: fv/cs/cm/ms/pm/gp/cr/vh, ассеты лежат в
  // assets/design/legend/badge-<code>.svg). Сама легенда — источник истины
  // по кодам и цветам, здесь только перевод "сырого" item.type (f/p/s/m/gp/
  // cr/v, как их хранит БД и редактор в app.js, см. groupOf()/CATEGORIES
  // там же) в код легенды. "ms" (Скины мутации) и "vh" (Ваучер) в живых
  // данных пока не встречаются — value уже готов к ним на будущее.
  // ==========================================================================
  var BADGE_CODES = ["fr", "cs", "cm", "ms", "pm", "gp", "cr", "vh"];
  var RAW_TYPE_TO_BADGE = {
    "": "fr", "f": "fr",
    "p": "pm",
    "s": "cs",
    "m": "cm",
    "gp": "gp",
    "cr": "cr",
    "v": "vh"
  };

  // Неизвестный/битый type не должен ронять рендер слота — по умолчанию
  // предмет считается обычным фруктом, ровно как groupOf() в app.js.
  function badgeCodeFor(type) {
    var t = typeof type === "string" ? type.trim().toLowerCase() : "";
    if (BADGE_CODES.indexOf(t) >= 0) { return t; } // данные уже хранят код легенды напрямую
    return Object.prototype.hasOwnProperty.call(RAW_TYPE_TO_BADGE, t) ? RAW_TYPE_TO_BADGE[t] : "fr";
  }

  // ==========================================================================
  //  Спрос — подсказка, не арифметика (см. комментарий у computeTrade)
  // ==========================================================================
  // Оценка каждого уровня спроса. Числа заданы заказчиком: оверпрайс 12,
  // хорошо 10, средне 8, ниже среднего 5, плохо 2. Оверпрайс стоит на вершине
  // шкалы, а не сбоку от неё: предмет, за который переплачивают, — самый
  // ходовой, отдать его легче всего.
  var DEMAND_WEIGHT = { neon: 12, green: 10, yellow: 8, orange: 5, red: 2 };

  // Средний балл спроса стороны, посчитанный по количеству предметов (не по
  // их цене — иначе это была бы та самая скрытая перевзвесовка value спросом,
  // которую ТЗ прямо запрещает). null — считать не по чему.
  //
  // Предметы без спроса в среднее НЕ входят вовсе — ни в сумму, ни в
  // делитель. Раньше они шли с весом 0, и на прежней шкале (-2..2) это был
  // её центр, то есть безобидная «середина»; на новой (2..12) ноль лежит ниже
  // самого низкого уровня, и один предмет без спроса утащил бы всю сторону
  // в «плохо». Пустая сторона и сторона, где спрос не проставлен никому, дают
  // null — точка остаётся серой.
  function demandBalance(entries) {
    var counted = 0;
    var weighted = 0;
    (entries || []).forEach(function (e) {
      var demand = e.item && e.item.demand;
      if (!Object.prototype.hasOwnProperty.call(DEMAND_WEIGHT, demand)) { return; }
      counted += 1;
      weighted += DEMAND_WEIGHT[demand];
    });
    if (counted === 0) { return null; }
    return weighted / counted;
  }

  // Балл спроса стороны → кружок легенды. Границы «строго выше»: оверпрайс
  // выше 10, хорошо 8..10, средне 5..8, ниже среднего 2..5, плохо 2 и ниже.
  // Ровно при таких границах каждый уровень попадает в свой же кружок
  // (12→neon, 10→green, 8→yellow, 5→orange, 2→red), то есть один предмет на
  // стороне показывает собственный спрос, а не «усреднённый» соседний.
  // null — считать не по чему (см. demandBalance выше).
  function demandBucket(balance) {
    if (typeof balance !== "number" || !isFinite(balance)) { return null; }
    if (balance > 10) { return "neon"; }
    if (balance > 8) { return "green"; }
    if (balance > 5) { return "yellow"; }
    if (balance > 2) { return "orange"; }
    return "red";
  }

  // ==========================================================================
  //  Вердикт: Win / Fair / Lose с точки зрения левой стороны («отдаю»)
  // ==========================================================================
  var THRESHOLD_PCT = 5;
  // Порог для demandNote подобран так, чтобы не сработать на одном предмете со
  // спорным спросом среди прочих ровных. На шкале 2..12 соседние уровни
  // отстоят на 2-3 балла, поэтому замена одного предмета из четырёх на уровень
  // ниже сдвигает средний балл меньше чем на 1, а заметная разница профилей
  // (почти всё «хорошо» против «ниже среднего»/«плохо») даёт 5 и больше.
  var DEMAND_NOTE_THRESHOLD = 3;

  // Диагностика конкретного предмета/строки в тестах и логах не нужна — здесь
  // считается только итог сделки.
  //
  // Проценты считаются от «отдающей» стороны (leftTotal): это самый понятный
  // для человека вопрос — «на сколько дороже/дешевле то, что я получаю,
  // относительно того, что отдаю». Если отдающая сторона пуста — делить не на
  // что, и знаменателем становится получаемая сторона (пусто → пусто = 0%,
  // пусто → что-то = обмен подарком, всегда WIN). totals остаются чистой
  // суммой value — спрос сюда не подмешивается никак, только в отдельное
  // необязательное поле demandNote ниже.
  function computeTrade(leftEntries, rightEntries, thresholdPct) {
    var threshold = typeof thresholdPct === "number" ? thresholdPct : THRESHOLD_PCT;
    var leftTotal = sideTotal(leftEntries);
    var rightTotal = sideTotal(rightEntries);
    var diffAbs = rightTotal - leftTotal;

    var basis = leftTotal !== 0 ? leftTotal : rightTotal;
    var diffPct = basis !== 0 ? (diffAbs / basis) * 100 : 0;

    var verdict;
    if (Math.abs(diffPct) <= threshold) { verdict = "fair"; }
    else { verdict = diffPct > 0 ? "win" : "lose"; }

    // Подсказка по спросу показывается только когда сделка и так близка к
    // честной по цифрам — именно тогда разница в спросе способна повлиять на
    // решение. При явном выигрыше/проигрыше цифры и так всё сказали.
    var demandNote = null;
    if (verdict === "fair") {
      var leftBalance = demandBalance(leftEntries);
      var rightBalance = demandBalance(rightEntries);
      if (leftBalance !== null && rightBalance !== null) {
        var balanceDiff = leftBalance - rightBalance;
        if (balanceDiff >= DEMAND_NOTE_THRESHOLD) { demandNote = "receiveLow"; }
        else if (balanceDiff <= -DEMAND_NOTE_THRESHOLD) { demandNote = "giveLow"; }
      }
    }

    return {
      leftTotal: leftTotal,
      rightTotal: rightTotal,
      diffAbs: diffAbs,
      diffPct: diffPct,
      verdict: verdict,
      demandNote: demandNote
    };
  }

  // ==========================================================================
  //  Ссылка «поделиться» — компактное кодирование двух сторон в URL
  // ==========================================================================
  // Токен — "id:count". id — из каталога (тот же id, что и в БД), count —
  // 1..999.
  //
  // Кодер теперь всегда пишет ":1": слот вмещает один предмет, и два
  // одинаковых дают два токена подряд, а не один со счётчиком. Формат при
  // этом не менялся — старые ссылки со счётчиком читаются как прежде,
  // разворачиваясь в отдельные слоты (см. decodeSide).
  var ID_TOKEN_RE = /^([A-Za-z0-9_-]{1,64}):([1-9]\d{0,2})$/;
  // Предохранители от враждебного параметра: не разбирать строку неограниченной
  // длины и не создавать неограниченно много токенов из одной ссылки.
  var MAX_RAW_LEN = 4000;
  var MAX_TOKENS = 200;
  var MAX_COUNT = 999;

  function encodeSide(entries) {
    return (entries || [])
      .filter(function (e) { return e && e.item && e.item.id; })
      .slice(0, MAX_SLOTS)
      .map(function (e) { return String(e.item.id) + ":1"; })
      .join(",");
  }

  // Строгий разбор одной стороны. Ничего не бросает: любой ввод, который не
  // укладывается в формат "id:count,id:count,…" c id из каталога, просто не
  // добавляет соответствующий слот в результат — вплоть до пустого массива на
  // полностью враждебной строке.
  //
  // Счётчик в токене разворачивается в слоты: "id:3" — это три слота подряд,
  // а не одна строка со счётчиком 3. Так читаются ссылки, разосланные до
  // перехода на «слот — один предмет». Один и тот же id несколькими токенами
  // тоже даёт несколько слотов: схлопывать их больше не во что.
  //
  // Потолок слотов соблюдает сам модуль, а не вызывающий: ссылка со ста
  // предметами не должна давать сторону, которую доска физически не способна
  // показать. Раньше обрезка жила только в capSide() страницы, и любой второй
  // потребитель (тест, серверный рендер превью) считал бы сумму по предметам,
  // которых на доске не видно.
  function decodeSide(raw, catalogIndex) {
    try {
      if (typeof raw !== "string" || raw === "") { return []; }
      if (raw.length > MAX_RAW_LEN) { return []; }
      var tokens = raw.split(",").slice(0, MAX_TOKENS);
      var slots = [];
      for (var i = 0; i < tokens.length && slots.length < MAX_SLOTS; i++) {
        var m = ID_TOKEN_RE.exec(tokens[i]);
        if (!m) { continue; }
        var id = m[1];
        if (!catalogIndex || !Object.prototype.hasOwnProperty.call(catalogIndex, id)) { continue; }
        var n = parseInt(m[2], 10);
        if (!Number.isFinite(n) || n < 1) { continue; }
        n = Math.min(MAX_COUNT, n);
        for (var k = 0; k < n && slots.length < MAX_SLOTS; k++) {
          slots.push({ item: catalogIndex[id], count: 1 });
        }
      }
      return slots;
    } catch (_e) {
      return [];
    }
  }

  function encodeShareQuery(leftEntries, rightEntries) {
    var params = [];
    var l = encodeSide(leftEntries);
    var r = encodeSide(rightEntries);
    if (l) { params.push("l=" + encodeURIComponent(l)); }
    if (r) { params.push("r=" + encodeURIComponent(r)); }
    return params.join("&");
  }

  // searchParamsLike — что угодно с методом .get(name): URLSearchParams в
  // браузере и в node (доступен глобально без импорта с node 10+), так что
  // тесты вызывают эту же функцию без подмены DOM.
  function decodeShareQuery(searchParamsLike, catalogIndex) {
    try {
      var l = searchParamsLike && typeof searchParamsLike.get === "function" ? searchParamsLike.get("l") : null;
      var r = searchParamsLike && typeof searchParamsLike.get === "function" ? searchParamsLike.get("r") : null;
      return {
        left: decodeSide(l || "", catalogIndex),
        right: decodeSide(r || "", catalogIndex)
      };
    } catch (_e) {
      return { left: [], right: [] };
    }
  }

  var api = {
    THRESHOLD_PCT: THRESHOLD_PCT,
    DEMAND_WEIGHT: DEMAND_WEIGHT,
    DEMAND_NOTE_THRESHOLD: DEMAND_NOTE_THRESHOLD,
    MAX_SLOTS: MAX_SLOTS,
    BADGE_CODES: BADGE_CODES,
    badgeCodeFor: badgeCodeFor,
    parseValue: parseValue,
    itemValue: itemValue,
    flattenTierlist: flattenTierlist,
    buildCatalogIndex: buildCatalogIndex,
    canAddToSide: canAddToSide,
    addToSide: addToSide,
    removeOneFromSide: removeOneFromSide,
    clearSide: clearSide,
    sideTotal: sideTotal,
    demandBalance: demandBalance,
    demandBucket: demandBucket,
    computeTrade: computeTrade,
    encodeSide: encodeSide,
    decodeSide: decodeSide,
    encodeShareQuery: encodeShareQuery,
    decodeShareQuery: decodeShareQuery
  };

  if (typeof module === "object" && module.exports) { module.exports = api; }
  root.CALC = api;
})(typeof globalThis !== "undefined" ? globalThis : this);
