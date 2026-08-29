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
  // ?v=share из чужой ссылки, ручную правку в devtools. Строгий формат (только
  // цифры, необязательный минус и дробная часть через точку) отсекает то, что
  // Number() пропустил бы молча: "12abc", "1,234", " ", "Infinity", "0x10".
  var VALUE_RE = /^-?\d+(\.\d+)?$/;

  function parseValue(raw) {
    if (typeof raw === "number") { return Number.isFinite(raw) ? raw : null; }
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
  function addToSide(entries, item, count) {
    var add = count > 0 ? count : 1;
    var found = false;
    var next = (entries || []).map(function (e) {
      if (e.item && item && e.item.id === item.id) {
        found = true;
        return { item: e.item, count: e.count + add };
      }
      return e;
    });
    if (!found && item) { next.push({ item: item, count: add }); }
    return next;
  }

  // Убирает один экземпляр предмета; при count===1 строка исчезает целиком.
  function removeOneFromSide(entries, id) {
    var next = [];
    (entries || []).forEach(function (e) {
      if (e.item && e.item.id === id) {
        if (e.count > 1) { next.push({ item: e.item, count: e.count - 1 }); }
      } else {
        next.push(e);
      }
    });
    return next;
  }

  function clearSide() { return []; }

  function sideTotal(entries) {
    return (entries || []).reduce(function (sum, e) {
      return sum + itemValue(e.item) * e.count;
    }, 0);
  }

  // ==========================================================================
  //  Спрос — подсказка, не арифметика (см. комментарий у computeTrade)
  // ==========================================================================
  var DEMAND_WEIGHT = { green: 2, yellow: 1, orange: -1, red: -2 };

  // Средний взвешенный «балл спроса» стороны, посчитанный по количеству
  // предметов (не по их цене — иначе это была бы та самая скрытая
  // перевзвесовка value спросом, которую ТЗ прямо запрещает). null — сторона
  // пуста, сравнивать не с чем.
  function demandBalance(entries) {
    var totalCount = (entries || []).reduce(function (s, e) { return s + e.count; }, 0);
    if (totalCount === 0) { return null; }
    var weighted = entries.reduce(function (s, e) {
      var demand = e.item && e.item.demand;
      var w = Object.prototype.hasOwnProperty.call(DEMAND_WEIGHT, demand) ? DEMAND_WEIGHT[demand] : 0;
      return s + w * e.count;
    }, 0);
    return weighted / totalCount;
  }

  // ==========================================================================
  //  Вердикт: Win / Fair / Lose с точки зрения левой стороны («отдаю»)
  // ==========================================================================
  var THRESHOLD_PCT = 5;
  // Порог для demandNote подобран так, чтобы не сработать на одном предмете со
  // спорным спросом среди прочих ровных — заметная разница профилей (например,
  // почти всё green с одной стороны и orange/red с другой) даёт разницу баллов
  // около 2-3, случайный шум одного предмета — меньше 1.5.
  var DEMAND_NOTE_THRESHOLD = 1.5;

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
  // 1..999 (Math.round + зажатие: дробные и отрицательные значения в состоянии
  // калькулятора появиться не могут, но кодер не должен полагаться на это).
  var ID_TOKEN_RE = /^([A-Za-z0-9_-]{1,64}):([1-9]\d{0,2})$/;
  // Предохранители от враждебного параметра: не разбирать строку неограниченной
  // длины и не создавать неограниченно много токенов из одной ссылки.
  var MAX_RAW_LEN = 4000;
  var MAX_TOKENS = 200;
  var MAX_COUNT = 999;

  function encodeSide(entries) {
    return (entries || [])
      .filter(function (e) { return e && e.item && e.item.id && e.count > 0; })
      .map(function (e) {
        var count = Math.min(MAX_COUNT, Math.max(1, Math.round(e.count)));
        return String(e.item.id) + ":" + String(count);
      })
      .join(",");
  }

  // Строгий разбор одной стороны. Ничего не бросает: любой ввод, который не
  // укладывается в формат "id:count,id:count,…" c id из каталога, просто не
  // добавляет соответствующую строку в результат — вплоть до пустого массива
  // на полностью враждебной строке. Одинаковый id несколько раз в строке —
  // счётчики суммируются (и зажимаются потолком), а не перезаписывают друг
  // друга.
  function decodeSide(raw, catalogIndex) {
    try {
      if (typeof raw !== "string" || raw === "") { return []; }
      if (raw.length > MAX_RAW_LEN) { return []; }
      var tokens = raw.split(",").slice(0, MAX_TOKENS);
      var order = [];
      var counts = Object.create(null);
      for (var i = 0; i < tokens.length; i++) {
        var m = ID_TOKEN_RE.exec(tokens[i]);
        if (!m) { continue; }
        var id = m[1];
        if (!catalogIndex || !Object.prototype.hasOwnProperty.call(catalogIndex, id)) { continue; }
        var n = parseInt(m[2], 10);
        if (!Number.isFinite(n) || n < 1) { continue; }
        if (!Object.prototype.hasOwnProperty.call(counts, id)) {
          counts[id] = 0;
          order.push(id);
        }
        counts[id] = Math.min(MAX_COUNT, counts[id] + n);
      }
      return order.map(function (id) { return { item: catalogIndex[id], count: counts[id] }; });
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
    parseValue: parseValue,
    itemValue: itemValue,
    flattenTierlist: flattenTierlist,
    buildCatalogIndex: buildCatalogIndex,
    addToSide: addToSide,
    removeOneFromSide: removeOneFromSide,
    clearSide: clearSide,
    sideTotal: sideTotal,
    demandBalance: demandBalance,
    computeTrade: computeTrade,
    encodeSide: encodeSide,
    decodeSide: decodeSide,
    encodeShareQuery: encodeShareQuery,
    decodeShareQuery: decodeShareQuery
  };

  if (typeof module === "object" && module.exports) { module.exports = api; }
  root.CALC = api;
})(typeof globalThis !== "undefined" ? globalThis : this);
