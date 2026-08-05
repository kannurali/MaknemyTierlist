// Тесты достройки состояния до постерного макета.
// Запуск: node --test tests/migrate_test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { migrate, hasCard } = require('../public_html/js/migrate.js');

const tier = (over = {}) => Object.assign({ id: 't', label: 'MK', items: [] }, over);

test('тир с известным логотипом получает карточку', () => {
    const s = migrate({ tiers: [tier({ logo: 'assets/logo-mk.png' })], footer: [] });
    assert.equal(s.tiers[0].card.role, 'Владелец');
    assert.equal(s.tiers[0].card.handle, '@mksvtn');
    assert.match(s.tiers[0].card.comment, /за деньги не продаюсь/);
});

test('логотип пламени заменяется молнией, карточка достаётся дизайнеру', () => {
    // Третий знак в макете сменился с 💧 на ⚡, и старые записи должны переехать.
    const s = migrate({ tiers: [tier({ logo: 'assets/logo-flame.png' })], footer: [] });
    assert.equal(s.tiers[0].logo, 'assets/logo-bolt.png');
    assert.equal(s.tiers[0].card.role, 'Дизайнер');
});

test('тир без логотипа карточку не получает', () => {
    const s = migrate({ tiers: [tier({ logo: '', label: 'не верьте скринам' })], footer: [] });
    assert.equal(s.tiers[0].card, undefined);
    assert.equal(hasCard(s.tiers[0]), false);
});

test('логотип, загруженный админом, карточку не получает', () => {
    // Придумывать роль незнакомой картинке нечего — тир остаётся обычным.
    const s = migrate({ tiers: [tier({ logo: 'images/abc123.webp' })], footer: [] });
    assert.equal(s.tiers[0].card, undefined);
});

test('миграция не затирает карточку, заполненную админом', () => {
    const own = { role: 'Босс', handle: '@custom', comment: 'своё' };
    const s = migrate({ tiers: [tier({ logo: 'assets/logo-mk.png', card: own })], footer: [] });
    assert.deepEqual(s.tiers[0].card, own);
});

test('повторный запуск ничего не меняет', () => {
    const once = migrate({
        tiers: [tier({ logo: 'assets/logo-glh.png' })],
        footer: [{ title: 'мой дискорд', sub: '', href: '' }],
    });
    const snapshot = JSON.stringify(once);
    assert.equal(JSON.stringify(migrate(once)), snapshot);
});

test('аватарка подбирается по заголовку ссылки', () => {
    const s = migrate({
        tiers: [],
        footer: [
            { title: 'наш ДИСКОРД' },
            { title: 'мой телеграм' },
            { title: 'новости blox fruits' },
            { title: 'ВСЕ РОЗЫГРЫШИ ТУТ' },
            { title: 'Charlotte TM' },
        ],
    });
    assert.deepEqual(s.footer.map(l => l.icon), [
        'assets/avatar-discord.png',
        'assets/avatar-tg.png',
        'assets/avatar-bfnews.png',
        'assets/avatar-giveaways.png',
        'assets/avatar-charlotte.png',
    ]);
});

test('ссылка без пары в макете остаётся без аватарки', () => {
    const s = migrate({ tiers: [], footer: [{ title: 'Сайт сделал' }] });
    assert.equal(s.footer[0].icon, '');
});

test('аватарка, выбранная админом, не перезаписывается', () => {
    const s = migrate({ tiers: [], footer: [{ title: 'мой дискорд', icon: 'images/own.webp' }] });
    assert.equal(s.footer[0].icon, 'images/own.webp');
});

test('состояние без тиров и подвала не роняет миграцию', () => {
    assert.doesNotThrow(() => migrate({}));
    assert.equal(migrate(null), null);
});

test('hasCard требует и логотип, и заполненное содержимое', () => {
    assert.equal(hasCard({ logo: 'a.png', card: { role: 'Р' } }), true);
    assert.equal(hasCard({ logo: 'a.png', card: { role: '', handle: '', comment: '' } }), false);
    assert.equal(hasCard({ logo: '', card: { role: 'Р' } }), false);
});
