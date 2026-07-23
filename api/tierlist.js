// Vercel Serverless Function — GET /api/tierlist
// ---------------------------------------------------------------------------
// Читает актуальные данные тирлиста + счётчик лайков напрямую из Firebase
// (серверная сторона), кэширует на edge ~30 сек и отдаёт чистый JSON с нашего
// домена.
//
// Зачем это нужно:
//   1) Снимает лимит одновременных подключений Firebase. Раньше каждый
//      посетитель держал постоянный websocket (fbRef.on) — на бесплатном плане
//      это упирается в 100 подключений, и на пике часть людей не получала данные.
//      Теперь к Firebase ходит только этот сервер (~1 запрос в 30 сек), а
//      посетители читают из edge-кэша.
//   2) Данные идут с нашего домена, а не с *.firebasedatabase.app — значит их
//      не режут блокировщики рекламы и школьные/рабочие/провайдерские фильтры.
// ---------------------------------------------------------------------------

const FB_DB = "https://nexus-117f0-default-rtdb.europe-west1.firebasedatabase.app";

export default async function handler(req, res) {
  // Кэширование зависит от того, пришёл ли ?rev=<n> (версия тирлиста).
  //
  //  • С rev — клиент запрашивает КОНКРЕТНУЮ версию (URL /api/tierlist?rev=123).
  //    Данные этой версии неизменны, поэтому кэшируем «навсегда» (immutable).
  //    Каждый rev тянется из Firebase РОВНО ОДИН раз (первый посетитель в
  //    регионе), дальше всё отдаётся из edge- и браузерного кэша. Именно это
  //    убирает перекачку одного и того же 2.7 МБ-блоба каждые 30 сек, из-за
  //    которой сгорал лимит трафика Firebase (142 ГБ/мес → единицы ГБ).
  //    При публикации админ меняет rev → у клиентов новый URL → одна свежая
  //    загрузка, старый rev больше никто не запрашивает.
  //  • Без rev (фолбэк/прямой заход) — прежнее поведение: короткий edge-кэш.
  const rev = req.query && req.query.rev;
  if (rev) {
    res.setHeader("Cache-Control", "public, max-age=31536000, s-maxage=31536000, immutable");
  } else {
    res.setHeader("Cache-Control", "s-maxage=30, stale-while-revalidate=60");
  }
  res.setHeader("Access-Control-Allow-Origin", "*");

  try {
    const [tRes, lRes] = await Promise.all([
      fetch(`${FB_DB}/tierlist.json`),
      fetch(`${FB_DB}/likes.json`),
    ]);
    const tierlist = tRes.ok ? await tRes.json() : null;
    const likesRaw = lRes.ok ? await lRes.json() : null;
    const likes = (typeof likesRaw === "number" && likesRaw >= 0) ? likesRaw : 0;
    res.status(200).json({ tierlist, likes });
  } catch (e) {
    res.status(502).json({ error: "firebase_unreachable" });
  }
}
