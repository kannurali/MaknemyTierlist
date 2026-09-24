(function (root) {
  "use strict";

  const SVG_NS = "http://www.w3.org/2000/svg";
  const API_STATE = "/api/state.php";
  const API_TIERLIST = "/api/tierlist.php";
  const API_CLOSE = "/api/trade_close.php";

  function el(tag, cls, text) {
    const node = document.createElement(tag);
    if (cls) { node.className = cls; }
    if (text !== undefined && text !== null) { node.textContent = text; }
    return node;
  }

  function icon(id, cls) {
    const svg = document.createElementNS(SVG_NS, "svg");
    svg.setAttribute("class", cls);
    svg.setAttribute("aria-hidden", "true");
    svg.setAttribute("focusable", "false");
    const use = document.createElementNS(SVG_NS, "use");
    use.setAttribute("href", "#" + id);
    svg.appendChild(use);
    return svg;
  }

  function silhouette() {
    const svg = document.createElementNS(SVG_NS, "svg");
    svg.setAttribute("viewBox", "0 0 34 34");
    svg.setAttribute("aria-hidden", "true");
    const a = document.createElementNS(SVG_NS, "circle");
    a.setAttribute("cx", "17"); a.setAttribute("cy", "10"); a.setAttribute("r", "7");
    a.setAttribute("fill", "currentColor");
    const b = document.createElementNS(SVG_NS, "path");
    b.setAttribute("d", "M5.6 26.5c0-4.6 4.2-8.1 9.1-8.1h4.6c4.9 0 9.1 3.5 9.1 8.1 0 2.6-5 4.6-11.4 4.6S5.6 29.1 5.6 26.5Z");
    b.setAttribute("fill", "currentColor");
    svg.appendChild(a);
    svg.appendChild(b);
    return svg;
  }

  function make(env) {
    const tx = env.tx;
    const st = env.state;

    function ago(ts) {
      const s = Math.max(0, Math.floor(Date.now() / 1000) - ts);
      if (s < 60) { return tx("trade.agoNow"); }
      if (s < 3600) { return tx("trade.agoMin", { n: Math.floor(s / 60) }); }
      if (s < 86400) { return tx("trade.agoHour", { n: Math.floor(s / 3600) }); }
      return tx("trade.agoDay", { n: Math.floor(s / 86400) });
    }

    function duration(sec) {
      const s = Math.max(60, sec);
      if (s >= 86400) { return tx("trade.durDay", { n: Math.floor(s / 86400) }); }
      if (s >= 3600) { return tx("trade.durHour", { n: Math.floor(s / 3600) }); }
      return tx("trade.durMin", { n: Math.ceil(s / 60) });
    }

    function itemName(id) {
      const it = st.catalog[id];
      return it && it.name ? it.name : "?";
    }

    function draftFor(offer) {
      const names = ids => ids.map(itemName).join(", ");
      return offer.want.length
        ? tx("trade.draft", { give: names(offer.give), want: names(offer.want) })
        : tx("trade.draftAny", { give: names(offer.give) });
    }

    function chatHref(offer) {
      return "/chat?to=" + encodeURIComponent(offer.author.id)
        + "&offer=" + encodeURIComponent(offer.id)
        + "&draft=" + encodeURIComponent(draftFor(offer));
    }

    function repostHref(offer) {
      const side = ids => ids.map(id => id + ":1").join(",");
      const q = [];
      if (offer.give.length) { q.push("l=" + encodeURIComponent(side(offer.give))); }
      if (offer.want.length) { q.push("r=" + encodeURIComponent(side(offer.want))); }
      return "/trading/new" + (q.length ? "?" + q.join("&") : "");
    }

    function buildItem(id) {
      const li = el("li", "tr-item");
      if (!st.catalogReady) {
        li.classList.add("is-pending");
        return li;
      }
      const it = st.catalog[id];
      if (!it) {
        li.classList.add("is-missing");
        li.title = tx("trade.itemMissing");
        li.appendChild(el("span", "tr-sr-only", tx("trade.itemMissing")));
        return li;
      }
      li.title = (it.name || "") + " — " + (it.value || "0");

      const badge = document.createElement("img");
      badge.className = "tr-item-badge";
      badge.src = "assets/design/legend/badge-" + CALC.badgeCodeFor(it.type) + ".svg";
      badge.alt = "";
      li.appendChild(badge);

      const pic = document.createElement("img");
      pic.className = "tr-item-icon";
      pic.src = it.icon || "";
      pic.alt = "";
      pic.loading = "lazy";
      pic.decoding = "async";
      li.appendChild(pic);

      li.appendChild(el("span", "tr-sr-only", it.name || ""));
      li.appendChild(el("span", "tr-item-value", it.value || "0"));
      return li;
    }

    function buildSide(ids, cls, labelKey) {
      const ul = el("ul", "tr-items " + cls);
      ul.setAttribute("aria-label", tx(labelKey));
      if (!ids.length) {
        ul.appendChild(el("li", "tr-any", tx("trade.anyOffer")));
        return ul;
      }
      ids.forEach(id => ul.appendChild(buildItem(id)));
      return ul;
    }

    function buildAvatar(author) {
      if (author.avatar) {
        const img = document.createElement("img");
        img.className = "tr-ava";
        img.src = author.avatar;
        img.alt = "";
        img.loading = "lazy";
        img.referrerPolicy = "no-referrer";
        return img;
      }
      const box = el("span", "tr-ava is-empty");
      box.setAttribute("aria-hidden", "true");
      box.appendChild(silhouette());
      return box;
    }

    function actButton(key, act, cls, id) {
      const b = el("button", "tr-act " + cls, tx(key));
      b.type = "button";
      b.dataset.act = act;
      b.dataset.id = String(id);
      return b;
    }

    const STATE_KEY = {
      open: "trade.stateOpen",
      expired: "trade.stateExpired",
      done: "trade.stateDone",
      cancelled: "trade.stateCancelled",
      removed: "trade.stateRemoved"
    };

    function buildCard(offer) {
      const state = offer.state || "open";
      const li = el("li", "tr-card");
      li.dataset.id = String(offer.id);
      if (offer.mine) { li.classList.add("is-mine"); }
      if (state !== "open") { li.classList.add("is-closed"); }

      const art = el("article", "tr-card-in");
      const nickId = "trNick" + offer.id + (offer.mine ? "m" : "");
      art.setAttribute("aria-labelledby", nickId);

      const head = el("header", "tr-card-head");
      head.appendChild(buildAvatar(offer.author));

      const who = el("div", "tr-who");
      let nick;
      if (st.authed && !offer.mine && env.linkNick !== false) {
        nick = el("a", "tr-nick", offer.author.nick);
        nick.href = "/profile?id=" + encodeURIComponent(offer.author.id);
        nick.title = tx("trade.profile");
      } else {
        nick = el("span", "tr-nick", offer.author.nick);
      }
      nick.id = nickId;
      who.appendChild(nick);
      if (offer.author.handle) { who.appendChild(el("span", "tr-handle", offer.author.handle)); }
      head.appendChild(who);

      const rep = el("span", "tr-rep");
      const up = el("span", "tr-rep-up");
      up.title = tx("trade.likes", { n: offer.author.likes });
      up.appendChild(icon("trIconUp", "tr-rep-icon"));
      up.appendChild(el("b", "", String(offer.author.likes)));
      const down = el("span", "tr-rep-down");
      down.title = tx("trade.dislikes", { n: offer.author.dislikes });
      down.appendChild(icon("trIconDown", "tr-rep-icon"));
      down.appendChild(el("b", "", String(offer.author.dislikes)));
      rep.appendChild(up);
      rep.appendChild(down);
      head.appendChild(rep);

      if (offer.mine && state !== "open") {
        const badge = el("span", "tr-badge", tx(STATE_KEY[state] || STATE_KEY.expired));
        badge.dataset.state = state;
        head.appendChild(badge);
      } else {
        const online = el("span", "tr-online");
        const status = offer.author.status === "online" ? "online" : "offline";
        online.dataset.status = status;
        online.setAttribute("role", "img");
        online.setAttribute("aria-label", tx("chat.status." + status));
        online.title = tx("chat.status." + status);
        head.appendChild(online);
      }
      art.appendChild(head);

      const body = el("div", "tr-card-body");
      const giveN = Math.max(1, offer.give.length);
      const wantN = Math.max(1, offer.want.length);
      body.style.setProperty("--n", String(giveN + wantN));
      body.style.setProperty("--m", String(Math.max(giveN, wantN)));
      body.classList.toggle("is-wide", giveN + wantN > 4);
      body.appendChild(buildSide(offer.give, "tr-give", "trade.give"));
      body.appendChild(icon("trIconSwap", "tr-swap"));
      body.appendChild(buildSide(offer.want, "tr-want", "trade.want"));
      art.appendChild(body);

      const foot = el("footer", "tr-card-foot");
      const meta = el("div", "tr-meta");
      const time = el("time", "tr-time", ago(offer.at));
      time.dateTime = new Date(offer.at * 1000).toISOString();
      meta.appendChild(time);
      if (offer.mine && state === "open" && offer.expires) {
        const left = offer.expires - Math.floor(Date.now() / 1000);
        const key = offer.replied ? "trade.replied" : "trade.expiresQuiet";
        meta.appendChild(el("span", "tr-note" + (offer.replied ? " is-good" : ""), tx(key, { t: duration(left) })));
      }
      foot.appendChild(meta);

      const actions = el("div", "tr-actions");
      if (offer.mine) {
        if (state === "open") {
          actions.appendChild(actButton("trade.done", "done", "tr-act-good", offer.id));
          actions.appendChild(actButton("trade.cancel", "cancel", "tr-act-ghost", offer.id));
        } else if (state === "expired") {
          actions.appendChild(actButton("trade.done", "done", "tr-act-good", offer.id));
        }
        if (state !== "open" && state !== "removed") {
          const again = el("a", "tr-act tr-act-ghost", tx("trade.repost"));
          again.href = repostHref(offer);
          actions.appendChild(again);
        }
      } else if (st.authed) {
        const a = el("a", "tr-act tr-stretch", tx("trade.write"));
        a.href = chatHref(offer);
        actions.appendChild(a);
      } else if (st.canLogin) {
        const a = el("a", "tr-act tr-stretch", tx("trade.loginToWrite"));
        a.href = env.loginUrl();
        actions.appendChild(a);
      }
      if (st.admin && !offer.mine && state === "open") {
        actions.appendChild(actButton("trade.remove", "remove", "tr-act-bad", offer.id));
      }
      foot.appendChild(actions);
      art.appendChild(foot);

      li.appendChild(art);
      return li;
    }

    return { buildCard: buildCard, ago: ago, duration: duration };
  }

  async function loadCatalog() {
    let rev = null;
    try {
      const r = await fetch(API_STATE, { cache: "no-store" });
      const s = r.ok ? await r.json() : null;
      rev = s && typeof s.rev === "number" ? s.rev : null;
    } catch (_) {}
    const url = API_TIERLIST + (rev !== null ? "?rev=" + encodeURIComponent(rev) : "");
    const r = await fetch(url, { cache: rev !== null ? "default" : "no-store" });
    if (!r.ok) { throw new Error("http " + r.status); }
    const d = await r.json();
    if (!d || !d.tierlist) { throw new Error("empty tierlist"); }
    return { rev: rev, index: CALC.buildCatalogIndex(CALC.flattenTierlist(d.tierlist)) };
  }

  const CONFIRM_KEY = { done: "trade.confirmDone", cancel: "trade.confirmCancel", remove: "trade.confirmRemove" };

  async function close(id, act, tx) {
    if (!window.confirm(tx(CONFIRM_KEY[act] || CONFIRM_KEY.cancel))) { return null; }
    try {
      const r = await fetch(API_CLOSE, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id: id, result: act })
      });
      const d = await r.json().catch(() => null);
      return !!((r.ok && d && d.ok) || (d && d.error === "closed"));
    } catch (_) {
      return false;
    }
  }

  root.TRADE_CARDS = { make: make, loadCatalog: loadCatalog, close: close, silhouette: silhouette };
})(window);
