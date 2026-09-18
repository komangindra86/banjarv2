import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

function worker({ online = true } = {}) {
    const handlers = {}, fetched = [], added = [], deleted = [], shown = [];
    const fallback = { body: 'generic offline' };
    const clientList = [];
    const context = {
        URL, Promise,
        self: {
            location: { origin: 'https://alpha.example.test' }, addEventListener: (name, fn) => { handlers[name] = fn; }, skipWaiting: async () => {},
            clients: { claim: async () => {}, matchAll: async () => clientList, openWindow: async url => { clientList.push({ url, focused: false, opened: true }); } },
            registration: { showNotification: async (title, options) => { shown.push({ title, options }); } },
        },
        caches: {
            open: async () => ({ addAll: async paths => added.push(...paths) }),
            match: async key => key === '/offline.html' ? fallback : undefined,
            keys: async () => ['banjar-static-v1', 'banjar-static-v2', 'other-app-cache'],
            delete: async key => deleted.push(key),
        },
        fetch: async request => { fetched.push(request); if (!online) throw new Error('offline'); return { body: 'private response' }; },
    };
    vm.runInNewContext(readFileSync(new URL('../public/sw.js', import.meta.url), 'utf8'), context);
    return { handlers, fetched, added, deleted, fallback, shown, clientList };
}

test('install caches only generic public assets; activation preserves unrelated caches', async () => {
    const w = worker(); let pending;
    w.handlers.install({ waitUntil: p => { pending = p; } }); await pending;
    assert.deepEqual(w.added, ['/offline.html', '/icons/icon-192.png', '/icons/icon-512.png']);
    w.handlers.activate({ waitUntil: p => { pending = p; } }); await pending;
    assert.deepEqual(w.deleted, ['banjar-static-v1']);
});

test('offline private navigations receive generic fallback without storing private HTML', async () => {
    for (const path of ['/login', '/admin', '/admin/finance', '/admin/finance/report', '/admin/finance/bills/1', '/portal', '/payments', '/payments/123', '/admin/payments', '/platform/payments/1']) {
        const w = worker({ online: false }); let response;
        w.handlers.fetch({ request: { url: 'https://alpha.example.test' + path, method: 'GET', mode: 'navigate' }, respondWith: p => { response = p; } });
        assert.equal(await response, w.fallback); assert.equal(w.added.length, 0);
    }
});

test('online navigation always uses network, so logout never reveals cached account pages', async () => {
    const w = worker(); let response;
    w.handlers.fetch({ request: { url: 'https://alpha.example.test/admin', method: 'GET', mode: 'navigate' }, respondWith: p => { response = p; } });
    assert.equal((await response).body, 'private response'); assert.equal(w.fetched.length, 1); assert.equal(w.added.length, 0);
});

test('POST, private APIs, signed URLs and cross-origin requests are not intercepted', () => {
    const w = worker(); let interceptions = 0;
    for (const request of [
        { url: 'https://alpha.example.test/logout', method: 'POST', mode: 'navigate' },
        { url: 'https://alpha.example.test/api/profile', method: 'GET', mode: 'cors' },
        { url: 'https://alpha.example.test/icons/icon-192.png?signature=secret', method: 'GET', mode: 'cors' },
        { url: 'https://beta.example.test/admin', method: 'GET', mode: 'navigate' },
    ]) w.handlers.fetch({ request, respondWith: () => { interceptions++; } });
    assert.equal(interceptions, 0); assert.equal(w.fetched.length, 0);
});

test('push payload renders only the generic server-provided text, falling back safely when missing or malformed', async () => {
    const w = worker();
    await w.handlers.push({ data: { json: () => ({ title: 'Surat baru', body: 'Ada pengajuan menunggu verifikasi.', url: '/admin/letters' }) }, waitUntil: p => p });
    assert.equal(w.shown[0].options.data.url, '/admin/letters');
    assert.equal(w.shown[0].title, 'Surat baru');
    assert.equal(w.shown[0].options.body, 'Ada pengajuan menunggu verifikasi.');

    const malformed = worker();
    await malformed.handlers.push({ data: { json: () => { throw new Error('bad payload'); } }, waitUntil: p => p });
    assert.equal(malformed.shown[0].title, 'Notifikasi Banjar');

    const empty = worker();
    await empty.handlers.push({ data: null, waitUntil: p => p });
    assert.equal(empty.shown[0].options.data.url, '/notifications');
});

test('notification click opens a new tab when none is already at the target URL', async () => {
    const w = worker();
    let closed = false;
    await w.handlers.notificationclick({ notification: { close: () => { closed = true; }, data: { url: '/admin/letters' } }, waitUntil: p => p });
    assert.equal(closed, true);
    assert.equal(w.clientList[0].opened, true);
});

test('notification click focuses an existing tab instead of opening a duplicate', async () => {
    const w = worker();
    const existing = { url: 'https://alpha.example.test/admin/letters', focused: false, focus: () => { existing.focused = true; } };
    w.clientList.push(existing);
    await w.handlers.notificationclick({ notification: { close: () => {}, data: { url: '/admin/letters' } }, waitUntil: p => p });
    assert.equal(existing.focused, true);
    assert.equal(w.clientList.length, 1);
});

