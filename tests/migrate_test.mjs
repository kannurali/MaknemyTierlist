// Тесты достройки состояния до нового макета.
// Запуск: node --test tests/migrate_test.mjs
//
// Главное правило, которое здесь проверяется: из макета берётся композиция, а
// не тексты. Роль и имя в карточке приходят из титров сайта, комментарий
// создаётся пустым — такого поля раньше не было, и наполнять его за админа
// нельзя.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { migrate, hasCard, visibleCredits } = require('../public_html/js/migrate.js');

const tier = (over = {}) => Object.assign({ id: 't', label: 'MK', items: [] }, over);
const CREDITS = [
    { role: 'Автор', name: 'mksvtn' },
    { role: 'Дизайнер', name: 'DAnikTda' },
    { role: 'Аналитик', name: 'GLH' },
    { role: 'Кодер сайта', name: 'Typeopozitivegg' },
];

test('карточка собирается из титров сайта, а не из макета', () => {
    const s = migrate({ tiers: [tier({ logo: 'assets/logo-mk.png' })], credits: CREDITS, footer: [] });
    assert.deepEqual(s.tiers[0].card, { role: 'Автор', handle: 'mksvtn', comment: '' });
});

test('комментарий создаётся пустым', () => {
    // На сайте такого поля не было. Любой непустой текст здесь означал бы, что
    // в карточку просочился контент макета.
    const s = migrate({ tiers: [tier({ logo: 'assets/logo-glh.png' })], credits: CREDITS, footer: [] });
    assert.equal(s.tiers[0].card.comment, '');
});

test('логотип пламени заменяется молнией, роль берётся из титров', () => {
    const s = migrate({ tiers: [tier({ logo: 'assets/logo-flame.png' })], credits: CREDITS, footer: [] });
    assert.equal(s.tiers[0].logo, 'assets/logo-bolt.png');
    assert.deepEqual(s.tiers[0].card, { role: 'Дизайнер', handle: 'DAnikTda', comment: '' });
});

test('роли нет в титрах — карточки не будет', () => {
    // Иначе на странице появилась бы роль, которой больше нигде нет.
    const s = migrate({ tiers: [tier({ logo: 'assets/logo-mk.png' })], credits: [], footer: [] });
    assert.equal(s.tiers[0].card, undefined);
});

test('тир без логотипа карточку не получает', () => {
    const s = migrate({ tiers: [tier({ logo: '', label: 'не верьте скринам' })], credits: CREDITS, footer: [] });
    assert.equal(s.tiers[0].card, undefined);
    assert.equal(hasCard(s.tiers[0]), false);
});

test('логотип, загруженный админом, карточку не получает', () => {
    const s = migrate({ tiers: [tier({ logo: 'images/abc123.webp' })], credits: CREDITS, footer: [] });
    assert.equal(s.tiers[0].card, undefined);
});

test('миграция не затирает карточку, заполненную админом', () => {
    const own = { role: 'Босс', handle: '@custom', comment: 'своё' };
    const s = migrate({ tiers: [tier({ logo: 'assets/logo-mk.png', card: own })], credits: CREDITS, footer: [] });
    assert.deepEqual(s.tiers[0].card, own);
});

test('повторный запуск ничего не меняет', () => {
    const once = migrate({
        tiers: [tier({ logo: 'assets/logo-glh.png' })],
        credits: CREDITS,
        footer: [{ title: 'мой дискорд', sub: '', href: '' }],
    });
    const snapshot = JSON.stringify(once);
    assert.equal(JSON.stringify(migrate(once)), snapshot);
});

test('аватарка подбирается по заголовку ссылки', () => {
    const s = migrate({
        tiers: [], credits: CREDITS,
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

test('заголовки ссылок не переписываются', () => {
    // Подписи в подвале — контент сайта. Миграция добавляет только аватарку.
    const footer = [{ title: 'наш ДИСКОРД', sub: 'discord.gg/lycoris', href: 'discord.gg/lycoris' }];
    const s = migrate({ tiers: [], credits: CREDITS, footer });
    assert.equal(s.footer[0].title, 'наш ДИСКОРД');
    assert.equal(s.footer[0].sub, 'discord.gg/lycoris');
});

test('ссылка без пары в макете остаётся без аватарки', () => {
    const s = migrate({ tiers: [], credits: CREDITS, footer: [{ title: 'Сайт сделал' }] });
    assert.equal(s.footer[0].icon, '');
});

test('аватарка, выбранная админом, не перезаписывается', () => {
    const s = migrate({ tiers: [], credits: CREDITS, footer: [{ title: 'мой дискорд', icon: 'images/own.webp' }] });
    assert.equal(s.footer[0].icon, 'images/own.webp');
});

test('состояние без тиров, титров и подвала не роняет миграцию', () => {
    assert.doesNotThrow(() => migrate({}));
    assert.equal(migrate(null), null);
});

test('hasCard требует и логотип, и заполненное содержимое', () => {
    assert.equal(hasCard({ logo: 'a.png', card: { role: 'Р', handle: 'и', comment: '' } }), true);
    assert.equal(hasCard({ logo: 'a.png', card: { role: '', handle: '', comment: '' } }), false);
    assert.equal(hasCard({ logo: '', card: { role: 'Р' } }), false);
});

// ---------- полоска титров ----------

test('участник, показанный карточкой, из полоски убирается', () => {
    const s = migrate({
        tiers: [tier({ logo: 'assets/logo-mk.png' })], credits: CREDITS, footer: [],
    });
    const rest = visibleCredits(s).map(c => c.role);
    assert.deepEqual(rest, ['Дизайнер', 'Аналитик', 'Кодер сайта']);
});

test('заглушка в имени не выносит из полоски чужие роли', () => {
    // Имя-заглушка в титрах — норма: «—», «ищем...». Пока ключом было одно
    // имя, карточка аналитика с именем «—» уводила за собой всех остальных с
    // тем же прочерком, и полоска исчезала целиком.
    const credits = [
        { role: 'Автор', name: 'Maknemy' },
        { role: 'Дизайнер', name: 'Maknemy' },
        { role: 'Аналитик', name: '—' },
        { role: 'Помощник аналитика', name: '—' },
        { role: 'Кодер сайта', name: '—' },
    ];
    const s = migrate({
        tiers: [
            tier({ id: 'a', logo: 'assets/logo-mk.png' }),
            tier({ id: 'b', logo: 'assets/logo-glh.png' }),
            tier({ id: 'c', logo: 'assets/logo-flame.png' }),
        ],
        credits, footer: [],
    });
    assert.deepEqual(visibleCredits(s).map(c => c.role), ['Помощник аналитика', 'Кодер сайта']);
});

test('собачка у ника в карточке не создаёт дубль в полоске', () => {
    const s = migrate({ tiers: [tier({ logo: 'assets/logo-mk.png' })], credits: CREDITS, footer: [] });
    s.tiers[0].card.handle = ' @MKSVTN ';
    assert.equal(visibleCredits(s).some(c => c.role === 'Автор'), false);
});

test('переименованный в карточке участник остаётся в полоске', () => {
    // Пара не совпала — строку оставляем. Показать человека дважды не страшно,
    // потерять его со страницы — страшно.
    const s = migrate({ tiers: [tier({ logo: 'assets/logo-mk.png' })], credits: CREDITS, footer: [] });
    s.tiers[0].card.handle = 'кто-то другой';
    assert.equal(visibleCredits(s).some(c => c.role === 'Автор'), true);
});

test('склейка роли и имени не даёт ложного совпадения', () => {
    // Ключом служит пара, а не строка через разделитель: «Автор» + «Мак»
    // и «АвторМак» + пустое имя — разные люди.
    const s = {
        tiers: [tier({ logo: 'assets/logo-mk.png', card: { role: 'Автор', handle: 'Мак', comment: '' } })],
        credits: [{ role: 'АвторМак', name: '' }, { role: 'Автор', name: 'Мак' }],
    };
    assert.deepEqual(visibleCredits(s).map(c => c.role), ['АвторМак']);
});

test('без карточек полоска показывается целиком', () => {
    assert.equal(visibleCredits({ tiers: [], credits: CREDITS }).length, CREDITS.length);
    assert.deepEqual(visibleCredits({}), []);
    assert.deepEqual(visibleCredits(null), []);
});
