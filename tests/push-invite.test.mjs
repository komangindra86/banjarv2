import { test } from 'node:test';
import assert from 'node:assert/strict';
import { shouldInvitePush, PUSH_INVITE_WAIT_MS } from '../resources/js/push-invite.js';

const base = { permission: 'default', subscribed: false, shownThisVisit: false, laterAt: null, now: 1_800_000_000_000 };

test('ajakan push muncul untuk perangkat yang belum berlangganan', () => {
    assert.equal(shouldInvitePush(base), true);
});

test('tidak muncul bila push aktif, izin ditolak, atau sudah tampil di kunjungan ini', () => {
    assert.equal(shouldInvitePush({ ...base, subscribed: true }), false);
    assert.equal(shouldInvitePush({ ...base, permission: 'denied' }), false);
    assert.equal(shouldInvitePush({ ...base, shownThisVisit: true }), false);
});

test('"Nanti saja" menunda 30 hari', () => {
    const laterAt = String(base.now - PUSH_INVITE_WAIT_MS + 60_000);
    assert.equal(shouldInvitePush({ ...base, laterAt }), false, 'kurang semenit dari 30 hari: belum muncul');
    assert.equal(shouldInvitePush({ ...base, laterAt: String(base.now - PUSH_INVITE_WAIT_MS) }), true, 'tepat 30 hari: muncul lagi');
});
