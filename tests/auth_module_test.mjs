// Общий модуль входа и выхода. Run: node --test tests/auth_module_test.mjs
//
// js/auth.js существует ровно затем, чтобы выход был написан ОДИН раз: он
// нужен и меню пользователя в шапке (js/topbar.js), и меню на карточке
// профиля (js/profile-page.js). Две копии одного fetch разъехались бы при
// первой же правке — например когда выход начнёт требовать токен.
//
// Проверки здесь поведенческие, а не текстовые: обе стороны зовут модуль по
// имени, и «вызов есть» доказывает только то, что строка не удалена.
// Перепутанный порядок в switchAccount (сначала увести, потом погасить
// сессию) grep не поймает, а человек после смены аккаунта остался бы в
// прежнем.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const src = readFileSync(new URL('../public_html/js/auth.js', import.meta.url), 'utf8');

// Модуль читает location и fetch из глобального окружения. Подставляем их
// параметрами обёртки, а не в globalThis: тесты не должны пачкать процесс
// друг другу.
function load({ pathname = '/profile', search = '', hash = '', fetchFails = false } = {}) {
    const calls = { fetch: [], assign: [], reload: 0 };
    const location = {
        pathname, search, hash,
        assign(url) { calls.assign.push(url); },
        reload() { calls.reload += 1; },
    };
    const fetchStub = (url, opts) => {
        calls.fetch.push({ url, opts });
        return fetchFails ? Promise.reject(new Error('offline')) : Promise.resolve({ ok: true });
    };
    const win = {};
    new Function('window', 'location', 'fetch', src)(win, location, fetchStub);
    return { auth: win.MKAuth, calls };
}

test('модуль публикуется под одним именем со всеми четырьмя действиями', () => {
    const { auth } = load();
    assert.ok(auth, 'window.MKAuth объявлен');
    for (const name of ['here', 'startUrl', 'logout', 'switchAccount']) {
        assert.equal(typeof auth[name], 'function', `MKAuth.${name} — функция`);
    }
});

// Флаг ?login= ставит наш же обработчик возврата с roblox.com. Утащить его в
// адрес возврата значит показать плашку «вход отменён» ещё раз после
// успешного входа.
test('here() снимает служебный ?login=, не трогая остальной адрес', () => {
    const cases = [
        [{ pathname: '/profile', search: '' }, '/profile'],
        [{ pathname: '/profile', search: '?login=cancelled' }, '/profile'],
        [{ pathname: '/news', search: '?id=7&login=error' }, '/news?id=7'],
        [{ pathname: '/news', search: '?login=expired&id=7' }, '/news?id=7'],
        [{ pathname: '/tierlist', search: '?a=1&b=2' }, '/tierlist?a=1&b=2'],
        [{ pathname: '/news', search: '?id=7', hash: '#post' }, '/news?id=7#post'],
    ];
    for (const [where, want] of cases) {
        const { auth } = load(where);
        assert.equal(auth.here(), want, JSON.stringify(where));
    }
});

test('startUrl() ведёт на вход и кодирует адрес возврата', () => {
    const { auth } = load({ pathname: '/profile' });
    assert.equal(auth.startUrl(), '/api/roblox_start.php?return=%2Fprofile');
    assert.equal(auth.startUrl('/news?id=7'), '/api/roblox_start.php?return=%2Fnews%3Fid%3D7',
        'переданный адрес кодируется целиком, иначе &id= оборвал бы параметр return');
});

// Выход обязан быть POST-ом: api/logout.php зовёт require_post(), и GET-ом
// его не выполнить — картинка на чужой странице не должна разлогинивать.
test('logout() шлёт POST без кеша и потом зовёт колбэк', async () => {
    const { auth, calls } = load();
    let done = 0;
    await auth.logout(() => { done += 1; });

    assert.equal(calls.fetch.length, 1, 'ровно один запрос');
    assert.equal(calls.fetch[0].url, '/api/logout.php');
    assert.equal(calls.fetch[0].opts.method, 'POST');
    assert.equal(calls.fetch[0].opts.cache, 'no-store');
    assert.equal(done, 1, 'колбэк вызван');
    assert.equal(calls.reload, 0, 'со своим колбэком страница не перезагружается');
});

test('logout() без колбэка перезагружает страницу', async () => {
    const { auth, calls } = load();
    await auth.logout();
    assert.equal(calls.reload, 1);
});

// Сеть могла отвалиться, а сессию сервер мог уже погасить. Оставить человека
// на странице с видом вошедшего — худший исход: он решит, что не вышел.
test('logout() доводит дело до конца даже при сетевой ошибке', async () => {
    const { auth, calls } = load({ fetchFails: true });
    let done = 0;
    await auth.logout(() => { done += 1; });
    assert.equal(done, 1, 'колбэк всё равно вызван');
    assert.equal(calls.assign.length, 0);
});

// Порядок здесь — это и есть смысл функции. Уйти на согласие Roblox не
// погасив сессию значит вернуться тем же пользователем.
test('switchAccount() сначала гасит сессию и только потом уводит на вход', async () => {
    const order = [];
    const calls = { fetch: [], assign: [] };
    const location = {
        pathname: '/profile', search: '', hash: '',
        assign(url) { order.push('assign'); calls.assign.push(url); },
        reload() { order.push('reload'); },
    };
    const fetchStub = (url, opts) => {
        order.push('fetch');
        calls.fetch.push({ url, opts });
        return Promise.resolve({ ok: true });
    };
    const win = {};
    new Function('window', 'location', 'fetch', src)(win, location, fetchStub);

    await win.MKAuth.switchAccount('/profile');

    assert.deepEqual(order, ['fetch', 'assign'], 'сначала выход, потом переход');
    assert.equal(calls.fetch[0].url, '/api/logout.php');
    assert.equal(calls.assign[0], '/api/roblox_start.php?return=%2Fprofile');
    assert.equal(order.includes('reload'), false, 'своя перезагрузка тут не нужна');
});

// Адреса ручек — часть контракта с сервером. Меняются они вместе с
// api/logout.php и api/roblox_start.php, и только здесь.
test('адреса входа и выхода объявлены в модуле по одному разу', () => {
    assert.equal((src.match(/\/api\/logout\.php/g) || []).length, 1);
    assert.equal((src.match(/\/api\/roblox_start\.php/g) || []).length, 1);
});
