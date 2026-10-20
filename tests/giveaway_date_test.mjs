// Время конца розыгрыша (date() в js/giveaway.js): на русской странице — по
// Москве с «МСК», как привыкла аудитория СНГ; на остальных — местное время
// посетителя с его поясом. Раньше «MSK» стояло на всех десяти языках.
// Run: node --test tests/giveaway_date_test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';

const SRC = fs.readFileSync(fileURLToPath(new URL('../public_html/js/giveaway.js', import.meta.url)), 'utf8');
const LOCALES = { ru: 'ru-RU', en: 'en-GB', de: 'de-DE', uk: 'uk-UA' };
const TS = 1790000000; // 2026-09-21 14:13 UTC, 17:13 по Москве

function gwFor(lang) {
  const doc = { documentElement: { lang }, addEventListener() {}, querySelectorAll() { return []; }, cookie: '' };
  const win = { location: { search: '', pathname: '/' }, document: doc, addEventListener() {} };
  win.window = win;
  win.I18N = {
    pageLang: () => lang,
    locale: () => LOCALES[lang],
    t: key => (key === 'gw.msk' ? 'МСК' : key),
  };
  const ctx = vm.createContext({ window: win, document: doc, location: win.location, Intl, Date, Math, JSON,
    setInterval() {}, clearInterval() {}, setTimeout() {}, console });
  vm.runInContext(SRC, ctx);
  return win.NX_GW;
}

test('Russian pages show Moscow time with МСК', () => {
  const s = gwFor('ru').date(TS);
  assert.match(s, /17:13 МСК$/);
});

test('other languages show the visitor\'s own time and zone, never MSK', () => {
  for (const lang of ['en', 'de', 'uk']) {
    const s = gwFor(lang).date(TS);
    const local = new Date(TS * 1000).toLocaleString(LOCALES[lang], { hour: '2-digit', minute: '2-digit' });
    assert.ok(!/MSK|МСК/.test(s), `${lang}: ${s}`);
    assert.ok(s.includes(local), `${lang}: local time ${local} in ${s}`);
    const zone = new Intl.DateTimeFormat(LOCALES[lang], { timeZoneName: 'short' }).formatToParts(new Date(TS * 1000))
      .find(p => p.type === 'timeZoneName').value;
    assert.ok(s.endsWith(zone), `${lang}: zone ${zone} in ${s}`);
  }
});
