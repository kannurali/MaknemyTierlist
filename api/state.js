// Vercel Serverless Function — GET /api/state
// ---------------------------------------------------------------------------
// Лёгкий эндпоинт для частого опроса: отдаёт только { rev, likes } (десятки
// байт), читая из Firebase лишь /tierlist/_rev и /likes.
//
// Зачем: полный /api/tierlist весит ~2 МБ (в данных зашита реклама base64).
// Гонять его на каждый опрос раз в 30 сек = сотни МБ на пользователя и быстрый
// выгорающий лимит трафика Vercel. Клиент опрашивает этот лёгкий эндпоинт и
// качает полные данные ТОЛЬКО когда rev изменился (админ обновил тирлист).
// rev пишется клиентом-админом в publish() как state._rev = Date.now().
// ---------------------------------------------------------------------------

const FB_DB = "https://nexus-117f0-default-rtdb.europe-west1.firebasedatabase.app";

export default async function handler(req, res) {
  res.setHeader("Cache-Control", "s-maxage=15, stale-while-revalidate=30");
  res.setHeader("Access-Control-Allow-Origin", "*");

  try {
    const [rRes, lRes] = await Promise.all([
      fetch(`${FB_DB}/tierlist/_rev.json`),
      fetch(`${FB_DB}/likes.json`),
    ]);
    const rev = rRes.ok ? await rRes.json() : null;
    const likesRaw = lRes.ok ? await lRes.json() : null;
    const likes = (typeof likesRaw === "number" && likesRaw >= 0) ? likesRaw : 0;
    res.status(200).json({ rev, likes });
  } catch (e) {
    res.status(502).json({ error: "firebase_unreachable" });
  }
}
