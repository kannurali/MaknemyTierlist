// Vercel Serverless Function — POST /api/like   body: { dir: 1 | -1 }
// ---------------------------------------------------------------------------
// Атомарно увеличивает/уменьшает счётчик лайков в Firebase через REST
// server-value increment. Посетителю больше не нужен постоянный websocket ради
// одного лайка — короткий POST на наш домен.
//
// Требует правило Realtime Database: на ветке /likes открыта публичная запись
//   "likes": { ".write": true }
// ---------------------------------------------------------------------------

const FB_DB = "https://nexus-117f0-default-rtdb.europe-west1.firebasedatabase.app";

export default async function handler(req, res) {
  res.setHeader("Access-Control-Allow-Origin", "*");

  if (req.method !== "POST") {
    res.status(405).json({ error: "method_not_allowed" });
    return;
  }

  let dir = 1;
  try {
    const body = typeof req.body === "string" ? JSON.parse(req.body) : (req.body || {});
    dir = body.dir === -1 ? -1 : 1;
  } catch (e) { /* по умолчанию +1 */ }

  try {
    const r = await fetch(`${FB_DB}/likes.json`, {
      method: "PUT",
      body: JSON.stringify({ ".sv": { increment: dir } }),
    });
    if (!r.ok) { res.status(502).json({ error: "write_failed" }); return; }
    res.status(200).json({ ok: true });
  } catch (e) {
    res.status(502).json({ error: "firebase_unreachable" });
  }
}
