// Kapan ajakan push (modal di beranda portal) boleh muncul: tidak bila push sudah aktif atau izin ditolak,
// paling banyak sekali per kunjungan, dan setelah "Nanti saja" baru muncul lagi 30 hari kemudian.
export const PUSH_INVITE_WAIT_MS = 30 * 24 * 60 * 60 * 1000;

export function shouldInvitePush({ permission, subscribed, shownThisVisit, laterAt, now = Date.now() }) {
    if (permission === 'denied' || subscribed || shownThisVisit) return false;
    const since = Number(laterAt || 0);

    return !since || now - since >= PUSH_INVITE_WAIT_MS;
}
