import { shouldInvitePush } from './push-invite.js';
import { serviceFee } from './service-fee.js';

if ('serviceWorker' in navigator && window.isSecureContext) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {
            // The online application remains usable without offline support.
        });
    });
}

document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!window.confirm(form.dataset.confirm)) event.preventDefault();
    });
});

document.querySelectorAll('form[data-saving]').forEach((form) => {
    form.addEventListener('submit', () => {
        const button = form.querySelector('button[type="submit"], button:not([type])');
        if (button) { button.dataset.originalText = button.textContent; button.disabled = true; button.textContent = 'Menyimpan…'; }
    });
});

window.addEventListener('pageshow', () => {
    document.querySelectorAll('button[data-original-text]').forEach((button) => {
        button.disabled = false;
        button.textContent = button.dataset.originalText;
        delete button.dataset.originalText;
    });
});

// Bayar sekaligus: ringkasan tagihan dicentang + biaya layanan aplikasi bertingkat (server tetap menghitung ulang).
document.querySelectorAll('form[data-qris-select]').forEach((form) => {
    let tiers = [];
    try { tiers = JSON.parse(form.dataset.feeTiers || '[]'); } catch { tiers = []; }
    const above = Number(form.dataset.feeAbove || 0);
    const rupiah = (value) => `Rp ${value.toLocaleString('id-ID')}`;
    const set = (selector, text) => form.querySelectorAll(selector).forEach((el) => { el.textContent = text; });
    const update = () => {
        const checked = [...form.querySelectorAll('input[name="bill_ids[]"]:checked')];
        const bills = checked.reduce((sum, box) => sum + Number(box.dataset.amount || 0), 0);
        const fee = serviceFee(bills, tiers, above);
        set('[data-qris-count]', String(checked.length));
        set('[data-qris-bills]', rupiah(bills));
        set('[data-qris-fee]', rupiah(fee));
        set('[data-qris-total]', rupiah(bills + fee));
        form.querySelectorAll('[data-qris-submit]').forEach((button) => { button.disabled = checked.length === 0; });
    };
    form.addEventListener('change', update);
    update();
});

// QRIS iPaymu dikirim sebagai teks (QrString); gambar QR-nya dibuat di sini.
document.querySelectorAll('canvas[data-qris]').forEach((canvas) => {
    import('qrcode').then(({ default: QRCode }) => QRCode.toCanvas(canvas, canvas.dataset.qris, { width: 288, margin: 2, errorCorrectionLevel: 'M' }))
        .catch(() => canvas.insertAdjacentHTML('afterend', '<p class="error-box">QR belum dapat ditampilkan. Muat ulang halaman.</p>'));
});

document.querySelectorAll('.qr-canvas').forEach((qrCanvas) => {
    const scope = qrCanvas.closest('section') ?? document;
    import('qrcode').then(async ({ default: QRCode }) => {
        await QRCode.toCanvas(qrCanvas, qrCanvas.dataset.url, { width: 768, margin: 4, errorCorrectionLevel: 'M' });
        const download = scope.querySelector('.qr-download');
        download.href = qrCanvas.toDataURL('image/png');
        download.hidden = false;
        scope.querySelector('.qr-status').textContent = 'QR siap diunduh dan dicetak.';
    }).catch(() => {
        scope.querySelector('.qr-status').textContent = 'QR belum dapat dibuat. Muat ulang halaman untuk mencoba kembali.';
    });
});

const financeTarget = document.querySelector('#finance-target');
if (financeTarget) {
    const updateFinanceTarget = () => {
        const unit = document.querySelector('#finance-unit');
        unit.hidden = financeTarget.value !== 'UNIT';
        unit.querySelector('select').disabled = unit.hidden;
    };
    financeTarget.addEventListener('change', updateFinanceTarget);
    updateFinanceTarget();
}
document.querySelector('#finance-print')?.addEventListener('click', () => window.print());

const assetKind = document.querySelector('#asset-kind');
if (assetKind) {
    const updateAssetKind = () => {
        const quantity = document.querySelector('#asset-quantity');
        quantity.hidden = assetKind.value !== 'QUANTITY';
        quantity.querySelector('input').disabled = quantity.hidden;
    };
    assetKind.addEventListener('change', updateAssetKind);
    updateAssetKind();
}

// Input Rupiah terformat langsung saat mengetik (Master Prompt §26.2). Tampilan (data-rupiah) terpisah
// dari nilai yang benar-benar dikirim (data-rupiah-raw, integer polos). Backend tetap validator utama.
document.querySelectorAll('[data-rupiah]').forEach((display) => {
    const wrap = display.closest('label');
    const hidden = wrap?.querySelector('[data-rupiah-raw]');
    const error = wrap?.querySelector('[data-rupiah-error]');
    if (!hidden || !error) return;
    const format = (digits) => (digits === '' ? '' : digits.replace(/\B(?=(\d{3})+(?!\d))/g, '.'));
    const digitsBefore = (str, pos) => (str.slice(0, pos).match(/\d/g) || []).length;
    const parse = (raw) => {
        const t = raw.trim();
        if (t === '') return { ok: true, digits: '' };
        if (/^-/.test(t)) return { ok: false, message: 'Nominal tidak boleh negatif.' };
        if (/,/.test(t)) return { ok: false, message: 'Gunakan angka bulat, tanpa koma desimal.' };
        // Titik di sini hanya pemisah ribuan yang kita sisipkan sendiri — saat mengetik di tengah angka,
        // posisi titik sementara bisa "tidak rapi" (mis. "1590.000") walau angkanya tetap valid, jadi
        // titik dilepas dulu sebelum divalidasi, bukan divalidasi posisinya.
        const stripped = t.replace(/^Rp\s*/i, '').replace(/\./g, '');
        if (/^[0-9]+$/.test(stripped)) return { ok: true, digits: stripped.replace(/^0+(?=\d)/, '') || '0' };
        return { ok: false, message: 'Nilai tidak valid. Gunakan angka saja.' };
    };
    const reformat = () => {
        const pos = display.selectionStart ?? display.value.length;
        const before = digitsBefore(display.value, pos);
        const result = parse(display.value);
        if (!result.ok) { error.textContent = result.message; display.setAttribute('aria-invalid', 'true'); return; }
        hidden.value = result.digits;
        error.textContent = '';
        display.removeAttribute('aria-invalid');
        if (result.digits === '') { display.value = ''; return; }
        display.value = 'Rp ' + format(result.digits);
        let newPos = 3; // setelah "Rp "
        let seen = 0;
        while (newPos < display.value.length && seen < before) { if (/\d/.test(display.value[newPos])) seen++; newPos++; }
        display.setSelectionRange(newPos, newPos);
    };
    display.addEventListener('input', reformat);
    display.addEventListener('blur', reformat);
});
document.querySelectorAll('form').forEach((form) => {
    form.addEventListener('submit', (event) => {
        form.querySelectorAll('[data-rupiah-required]').forEach((hidden) => {
            if (hidden.value === '') {
                event.preventDefault();
                const error = hidden.closest('label')?.querySelector('[data-rupiah-error]');
                if (error) error.textContent = 'Nominal wajib diisi.';
            }
        });
    });
});

// Pemilihan checkbox dedosan lintas halaman/filter (Master Prompt §29.1). Pilihan disimpan di server
// (bukan hanya di browser) sehingga tetap benar walau pengguna berpindah halaman atau mengubah filter.
const candidatePicker = document.querySelector('[data-candidate-picker]');
if (candidatePicker) {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
    const base = candidatePicker.dataset.eventUrl;
    const summaryText = candidatePicker.querySelector('[data-selection-summary]');
    const post = (path, body) => fetch(base + path, {
        method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' }, body: JSON.stringify(body),
    }).then(async (res) => { const json = await res.json(); if (!res.ok) throw new Error(json.message || 'Gagal memproses pilihan.'); return json; });
    const applySummary = (summary) => {
        summaryText.textContent = `${summary.count_people} orang · ${summary.count_kk} KK · total ${summary.total_amount ? 'Rp ' + String(summary.total_amount).replace(/\B(?=(\d{3})+(?!\d))/g, '.') : 'Rp 0'}`;
    };
    // Checkbox baris berada di luar kartu ringkasan yang sticky (daftar bisa panjang/dipaginasi), jadi dicari
    // di seluruh halaman, bukan hanya di dalam candidatePicker.
    document.querySelectorAll('[data-candidate-checkbox]').forEach((box) => {
        box.addEventListener('change', () => {
            post('/candidates/toggle', { krama_id: Number(box.value), checked: box.checked })
                .then(applySummary).catch((e) => { box.checked = !box.checked; window.alert(e.message); });
        });
    });
    candidatePicker.querySelector('[data-select-page]')?.addEventListener('click', () => {
        const ids = [...document.querySelectorAll('[data-candidate-checkbox]:not(:disabled)')].map((b) => Number(b.value));
        post('/candidates/select-page', { krama_ids: ids }).then((summary) => {
            document.querySelectorAll('[data-candidate-checkbox]:not(:disabled)').forEach((b) => { b.checked = true; });
            applySummary(summary);
        }).catch((e) => window.alert(e.message));
    });
    candidatePicker.querySelector('[data-select-filtered]')?.addEventListener('click', () => {
        const params = new URLSearchParams(window.location.search);
        post('/candidates/select-filtered', { q: params.get('q') || '', unit_id: params.get('unit_id') || '' })
            .then(applySummary).catch((e) => window.alert(e.message));
    });
    candidatePicker.querySelector('[data-clear-selection]')?.addEventListener('click', () => {
        if (!window.confirm('Bersihkan seluruh pilihan?')) return;
        post('/candidates/clear', {}).then((summary) => {
            document.querySelectorAll('[data-candidate-checkbox]').forEach((b) => { b.checked = false; });
            applySummary(summary);
        }).catch((e) => window.alert(e.message));
    });
}

// Saring pilihan kategori sesuai jenis transaksi (Pemasukan/Pengeluaran) yang sedang dipilih.
const cashDirection = document.querySelector('#cash-direction');
if (cashDirection) {
    const categorySelect = document.querySelector('#cash-category');
    const quickKind = document.querySelector('#quick-category-kind');
    const kindOf = (dir) => (dir === 'OUT' ? 'OUT' : 'IN');
    const syncCategoryOptions = () => {
        const kind = kindOf(cashDirection.value);
        let stillValid = false;
        [...categorySelect.options].forEach((opt) => {
            if (!opt.value) return;
            const matches = opt.dataset.kind === kind;
            opt.hidden = !matches;
            if (matches && opt.selected) stillValid = true;
        });
        if (!stillValid) categorySelect.value = '';
        if (quickKind) quickKind.value = kind;
    };
    cashDirection.addEventListener('change', syncCategoryOptions);
    syncCategoryOptions();
}

// "+ Tambah kategori" tanpa meninggalkan formulir transaksi (Master Prompt §26.3, §58.2): dialog di
// halaman yang sama, form utama tidak pernah disentuh baik saat berhasil, batal, maupun gagal.
document.querySelectorAll('[data-open-dialog]').forEach((btn) => {
    btn.addEventListener('click', () => document.querySelector(btn.dataset.openDialog)?.showModal());
});
// Dialog generik: terbuka otomatis begitu halaman dimuat (mis. baru saja mengubah status krama jadi
// Meninggal), dan tombol tutup generik yang tidak bergantung pada logika form tertentu.
document.querySelectorAll('dialog[data-auto-open]').forEach((dialog) => dialog.showModal());
document.querySelectorAll('select[data-auto-submit]').forEach((select) => select.addEventListener('change', () => select.form?.submit()));

// Profil website: isi kolom dengan contoh kalimat (minta konfirmasi bila kolom sudah berisi).
document.querySelectorAll('[data-fill-template]').forEach((button) => {
    button.addEventListener('click', () => {
        const field = document.querySelector(button.dataset.fillTemplate);
        if (!field || (field.value.trim() && !window.confirm('Ganti isi kolom ini dengan contoh kalimat?'))) return;
        field.value = button.dataset.template;
        field.focus();
        field.dispatchEvent(new Event('input', { bubbles: true }));
    });
});

// Pengaturan website: memilih template ikut mengisi warna aksen yang serasi (masih bisa diubah).
document.querySelectorAll('input[data-template-accent]').forEach((radio) => {
    radio.addEventListener('change', () => {
        const accent = document.querySelector(radio.dataset.accentTarget);
        if (radio.checked && accent) accent.value = radio.dataset.templateAccent;
    });
});
document.querySelectorAll('dialog [data-close-dialog]').forEach((btn) => {
    btn.addEventListener('click', () => btn.closest('dialog')?.close());
});
// Formulir "tambah cepat" dalam dialog (kategori kas): submit via fetch tanpa meninggalkan halaman, lalu hasilnya
// dipasang ke <select> tujuan (data-target-select) — form utama tidak pernah disentuh baik berhasil maupun gagal.
document.querySelectorAll('[data-quick-add]').forEach((form) => {
    const dialog = form.closest('dialog');
    const errorBox = form.querySelector('[data-quick-error]');
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        errorBox.textContent = '';
        const button = form.querySelector('button[type="submit"]');
        button.disabled = true;
        try {
            const res = await fetch(form.action, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' }, body: new FormData(form) });
            const json = await res.json();
            if (!res.ok) { errorBox.textContent = json.message || 'Gagal menyimpan.'; return; }
            const label = json.label ?? json.name;
            const select = form.dataset.targetSelect ? document.querySelector(form.dataset.targetSelect) : null;
            if (select) select.add(new Option(label, String(json.id), true, true));
            dialog?.close();
            form.reset();
        } catch {
            errorBox.textContent = 'Gagal menghubungi server. Periksa koneksi dan coba lagi.';
        } finally {
            button.disabled = false;
        }
    });
    dialog?.querySelector('[data-cancel-dialog]')?.addEventListener('click', () => { errorBox.textContent = ''; form.reset(); dialog.close(); });
});

// Pemilih foto (komponen photo-picker, Master Prompt §18/§58): foto dipilih langsung — bisa beberapa sekaligus —
// lalu tampil sebagai pratinjau dan ikut terkirim saat formulir disimpan. Foto kamera HP diperkecil dulu di
// browser (sisi terpanjang 1600 px) agar unggahan cepat dan muat dalam batas server.
const PHOTO_MAX_SIDE = 1600;
const UPLOAD_LIMIT = 20 * 1024 * 1024;
const MAX_FILES = 20;

async function shrinkPhoto(file) {
    if (!file.type.startsWith('image/') || file.type === 'image/gif' || typeof createImageBitmap !== 'function') return file;
    try {
        const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
        const scale = Math.min(1, PHOTO_MAX_SIDE / Math.max(bitmap.width, bitmap.height));
        const keepsAlpha = file.type === 'image/png' || file.type === 'image/webp';
        if (scale === 1 && file.size < 1.5 * 1024 * 1024 && /^image\/(jpeg|png|webp)$/.test(file.type)) return file;
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(bitmap.width * scale);
        canvas.height = Math.round(bitmap.height * scale);
        canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        bitmap.close?.();
        const type = keepsAlpha ? 'image/png' : 'image/jpeg';
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, type, 0.85));
        if (!blob || blob.size >= file.size) return file;
        const name = file.name.replace(/\.[^.]+$/, '') + (type === 'image/png' ? '.png' : '.jpg');
        return new File([blob], name, { type, lastModified: file.lastModified });
    } catch {
        return file;
    }
}

document.querySelectorAll('[data-photo-picker]').forEach((picker) => {
    const input = picker.querySelector('[data-photo-input]');
    const grid = picker.querySelector('[data-photo-grid]');
    const single = picker.querySelector('[data-photo-keep-single]');
    const multiple = picker.hasAttribute('data-multiple');
    const canReplaceFiles = typeof DataTransfer === 'function';
    const status = document.createElement('span');
    status.className = 'field-error';
    status.setAttribute('role', 'status');
    picker.append(status);
    let chosen = [];

    const syncInput = () => {
        if (!canReplaceFiles) return;
        const transfer = new DataTransfer();
        chosen.forEach((file) => transfer.items.add(file));
        input.files = transfer.files;
    };
    const tile = (file, onRemove) => {
        const figure = document.createElement('figure');
        figure.className = 'photo-tile is-new';
        if (file.type.startsWith('image/')) {
            const img = document.createElement('img');
            img.alt = '';
            img.src = URL.createObjectURL(file);
            img.onload = () => URL.revokeObjectURL(img.src);
            figure.append(img);
        } else {
            const doc = document.createElement('span');
            doc.className = 'photo-doc';
            doc.textContent = 'PDF';
            figure.append(doc);
        }
        const caption = document.createElement('figcaption');
        caption.textContent = file.name;
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'photo-remove';
        remove.textContent = 'Hapus';
        remove.setAttribute('aria-label', `Hapus ${file.name}`);
        remove.addEventListener('click', onRemove);
        figure.append(caption, remove);
        return figure;
    };
    const render = () => {
        grid.querySelectorAll('.photo-tile.is-new').forEach((el) => el.remove());
        chosen.forEach((file, index) => grid.append(tile(file, () => { chosen.splice(index, 1); syncInput(); render(); })));
        const total = chosen.reduce((sum, file) => sum + file.size, 0);
        status.textContent = chosen.length > MAX_FILES ? `Paling banyak ${MAX_FILES} foto sekali simpan. Hapus sebagian foto dulu.`
            : total > UPLOAD_LIMIT ? 'Ukuran foto terlalu besar untuk sekali simpan. Hapus sebagian foto, simpan, lalu tambahkan lagi.' : '';
    };

    // Foto lama: tombol Hapus melepas foto dari konten (berkasnya tetap ada di Semua foto & berkas).
    grid.addEventListener('click', (event) => {
        const button = event.target.closest('[data-photo-remove]');
        if (!button) return;
        button.closest('[data-photo-tile]')?.remove();
        if (single) single.value = '';
    });
    input.addEventListener('change', async () => {
        const picked = [...input.files];
        if (!picked.length) { syncInput(); return; }
        status.textContent = 'Menyiapkan foto…';
        const prepared = await Promise.all(picked.map(shrinkPhoto));
        if (multiple) {
            // Tanpa DataTransfer (peramban lama) isi input tidak bisa digabung; pilihan terakhir yang terkirim.
            chosen = canReplaceFiles ? chosen.concat(prepared) : picked;
        } else {
            // Satu foto (logo, sampul, dokumen): pilihan baru menggantikan foto lama.
            chosen = prepared.slice(0, 1);
            grid.querySelectorAll('[data-photo-tile]').forEach((el) => el.remove());
            if (single) single.value = '';
        }
        syncInput();
        render();
    });
    input.form?.addEventListener('submit', (event) => {
        if (status.textContent && status.textContent !== 'Menyiapkan foto…') {
            event.preventDefault();
            event.stopImmediatePropagation();
            status.scrollIntoView({ block: 'center' });
        }
    }, true);
});

// Sembunyikan/nonaktifkan field cakupan unit kecuali peran terpilih Kesinoman/Kelian (Master Prompt §29.2).
document.querySelectorAll('[data-role-select]').forEach((select) => {
    const wrap = select.closest('form')?.querySelector('[data-scope-fields]');
    if (!wrap) return;
    const sync = () => {
        const code = select.selectedOptions[0]?.dataset.code;
        const allowed = code === 'KESINOMAN' || code === 'KELIAN';
        wrap.hidden = !allowed;
        wrap.querySelectorAll('select,input').forEach((el) => { el.disabled = !allowed; });
    };
    select.addEventListener('change', sync);
    sync();
});

// Satu klik menandai "Pilih untuk dedosan" pada setiap KK yang masih PENDING dan punya kepala keluarga,
// memakai pilihan yang sudah disarankan di dropdown penanggung jawab — Kesinoman tinggal tinjau lalu kirim.
document.querySelectorAll('[data-select-all-suggested]').forEach((button) => {
    button.addEventListener('click', () => {
        button.closest('form')?.querySelectorAll('[data-candidate-status="PENDING"]').forEach((row) => {
            const decision = row.querySelector('select[name$="[decision]"]');
            const responsible = row.querySelector('select[name$="[responsible_krama_id]"]');
            if (decision && responsible && responsible.options.length) decision.value = 'SELECT';
        });
    });
});

// Notifikasi push: berlangganan dipakai bersama oleh tombol di halaman Notifikasi dan ajakan di beranda portal.
const pushSupported = 'serviceWorker' in navigator && window.isSecureContext && 'PushManager' in window && 'Notification' in window;
const pushPost = (path, body) => fetch(path, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content, Accept: 'application/json' },
    body: JSON.stringify(body),
});
const urlBase64ToUint8Array = (base64) => {
    const padding = '='.repeat((4 - (base64.length % 4)) % 4);
    const raw = window.atob((base64 + padding).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from([...raw].map((c) => c.charCodeAt(0)));
};
const subscribePush = async (registration, vapidKey) => {
    const sub = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlBase64ToUint8Array(vapidKey) });
    const json = sub.toJSON();
    const response = await pushPost('/notifications/subscribe', { endpoint: json.endpoint, p256dh: json.keys.p256dh, auth: json.keys.auth, user_agent: navigator.userAgent.slice(0, 255) });
    if (!response.ok) throw new Error('subscribe failed');
    return sub;
};

const pushToggle = document.querySelector('#push-toggle');
if (pushToggle && pushSupported) {
    const status = document.querySelector('#push-status');
    const refresh = async () => {
        const registration = await navigator.serviceWorker.ready;
        const subscription = await registration.pushManager.getSubscription();
        pushToggle.hidden = false;
        if (subscription) {
            status.textContent = 'Notifikasi push aktif di perangkat ini.';
            pushToggle.textContent = 'Nonaktifkan notifikasi push';
            pushToggle.onclick = async () => {
                await subscription.unsubscribe();
                await pushPost('/notifications/unsubscribe', { endpoint: subscription.endpoint });
                refresh();
            };
        } else {
            status.textContent = 'Notifikasi push belum aktif di perangkat ini.';
            pushToggle.textContent = 'Aktifkan notifikasi push';
            pushToggle.onclick = async () => {
                try {
                    await subscribePush(registration, pushToggle.dataset.vapidKey);
                    refresh();
                } catch {
                    status.textContent = 'Izin notifikasi ditolak atau tidak tersedia di perangkat ini.';
                }
            };
        }
    };
    refresh().catch(() => { status.textContent = 'Notifikasi push tidak tersedia di perangkat ini.'; });
}

// Ajakan push di beranda portal (modal). Muncul hanya bila push belum aktif dan izin belum ditolak. "Nanti saja"
// (atau menutup modal) menunda 30 hari; dalam satu kunjungan modal paling banyak muncul sekali.
const pushInvite = document.querySelector('dialog[data-push-invite]');
if (pushInvite && pushSupported && Notification.permission !== 'denied') {
    const LATER_KEY = 'banjar-push-invite-later-at';
    const SHOWN_KEY = 'banjar-push-invite-shown';
    const read = (storage, key) => { try { return storage.getItem(key); } catch { return null; } };
    const write = (storage, key, value) => { try { storage.setItem(key, value); } catch { /* penyimpanan diblokir: abaikan */ } };
    const status = pushInvite.querySelector('[data-push-invite-status]');
    let accepted = false;
    const later = () => { if (!accepted) write(localStorage, LATER_KEY, String(Date.now())); };
    pushInvite.querySelector('[data-push-invite-later]')?.addEventListener('click', () => { later(); pushInvite.close(); });
    pushInvite.addEventListener('cancel', later);
    (async () => {
        const registration = await navigator.serviceWorker.ready;
        const subscribed = Boolean(await registration.pushManager.getSubscription());
        if (!shouldInvitePush({ permission: Notification.permission, subscribed, shownThisVisit: Boolean(read(sessionStorage, SHOWN_KEY)), laterAt: read(localStorage, LATER_KEY) })) return;
        write(sessionStorage, SHOWN_KEY, '1');
        pushInvite.querySelector('[data-push-invite-accept]')?.addEventListener('click', async (event) => {
            const button = event.currentTarget;
            button.disabled = true;
            status.textContent = 'Menunggu izin dari HP…';
            try {
                await subscribePush(registration, pushInvite.dataset.vapidKey);
                accepted = true;
                pushInvite.close();
            } catch {
                // Izin ditolak: jangan tanyakan lagi selama 30 hari (bila diblokir permanen, modal tidak muncul lagi).
                later();
                status.textContent = 'Izin notifikasi tidak diberikan. Anda dapat mengaktifkannya nanti di menu Notifikasi.';
                button.disabled = false;
            }
        });
        pushInvite.showModal();
    })().catch(() => {});
}
