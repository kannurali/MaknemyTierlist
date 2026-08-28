// Unit tests for the shared copy-protection module.
// Run: node --test tests/protect_test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const PROTECT = require('../public_html/js/protect.js');

// Minimal stand-in for `document`: collects the handlers install() registers
// so each event can be fired without a browser.
function fakeDoc() {
    const handlers = {};
    return {
        classes: new Set(),
        handlers,
        addEventListener(type, fn) { (handlers[type] ||= []).push(fn); },
        body: {
            classList: {
                toggle(name, on) {
                    // `on` is always passed explicitly by applyClass.
                    if (on) { this._set.add(name); } else { this._set.delete(name); }
                },
                _set: new Set(),
            },
        },
        // Fires every handler for `type` against a fake event, returns whether
        // any of them called preventDefault().
        fire(type, target = null) {
            let blocked = false;
            const ev = { target, preventDefault() { blocked = true; } };
            for (const fn of handlers[type] || []) { fn(ev); }
            return blocked;
        },
    };
}

// The four events a guest must not get: right-click menu, copy, cut, drag,
// plus selectstart for engines that ignore user-select: none.
const GUARDED = ['contextmenu', 'copy', 'cut', 'dragstart', 'selectstart'];

test('every guarded event is blocked for a guest', () => {
    const doc = fakeDoc();
    PROTECT.install(() => false, doc);
    for (const type of GUARDED) {
        assert.equal(doc.fire(type), true, type + ' blocked');
    }
});

test('nothing is blocked for the admin', () => {
    const doc = fakeDoc();
    PROTECT.install(() => true, doc);
    for (const type of GUARDED) {
        assert.equal(doc.fire(type), false, type + ' allowed');
    }
});

// The role is read on every event, not captured at install time: on the tier
// list the session answer arrives after the handlers are already in place.
test('the role is re-read on each event, not frozen at install', () => {
    const doc = fakeDoc();
    let admin = false;
    PROTECT.install(() => admin, doc);
    assert.equal(doc.fire('copy'), true, 'guest blocked before login');
    admin = true;
    assert.equal(doc.fire('copy'), false, 'admin allowed after login');
});

// A text field inside a protected page must keep working — otherwise the
// admin login form could not be typed into or pasted into.
test('input, textarea and contenteditable stay usable for a guest', () => {
    const editable = { closest: sel => (sel.includes('input') ? {} : null) };
    assert.equal(PROTECT.inEditable(editable), true);

    const doc = fakeDoc();
    PROTECT.install(() => false, doc);
    for (const type of ['contextmenu', 'copy', 'cut', 'selectstart']) {
        assert.equal(doc.fire(type, editable), false, type + ' passes through in a field');
    }
});

test('plain page content is not treated as editable', () => {
    const plain = { closest: () => null };
    assert.equal(PROTECT.inEditable(plain), false);
    assert.equal(PROTECT.inEditable(null), false);
    assert.equal(PROTECT.inEditable({}), false, 'a target without closest() is not editable');
});

// dragstart has no editable exception on purpose: dragging out of a text field
// is still dragging content out of the page.
test('dragging is blocked for a guest even from a field', () => {
    const doc = fakeDoc();
    PROTECT.install(() => false, doc);
    assert.equal(doc.fire('dragstart', { closest: () => ({}) }), true);
});

test('the body class follows the role', () => {
    const doc = fakeDoc();
    PROTECT.applyClass(false, doc);
    assert.equal(doc.body.classList._set.has('protected'), true, 'guest is protected');
    PROTECT.applyClass(true, doc);
    assert.equal(doc.body.classList._set.has('protected'), false, 'admin is not');
});
