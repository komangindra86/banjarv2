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
document.querySelectorAll('dialog [data-close-dialog]').forEach((btn) => {
    btn.addEventListener('click', () => btn.closest('dialog')?.close());
});
// Formulir "tambah cepat" dalam dialog (kategori kas, unggah foto langsung dari form konten): submit via
// fetch tanpa meninggalkan halaman, lalu hasilnya dipasang ke <select> (data-target-select) atau daftar
// checkbox (data-target-checklist) tujuan — form utama tidak pernah disentuh baik berhasil maupun gagal.
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
            const checklist = form.dataset.targetChecklist ? document.querySelector(form.dataset.targetChecklist) : null;
            if (checklist) {
                checklist.querySelector('[data-checklist-empty]')?.remove();
                const item = document.createElement('label');
                item.className = 'check-option';
                item.innerHTML = `<input type="checkbox" name="${checklist.dataset.checkboxName}" value="${json.id}" checked><span></span>`;
                item.querySelector('span').textContent = label;
                checklist.prepend(item);
            }
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

const pushToggle = document.querySelector('#push-toggle');
if (pushToggle && 'serviceWorker' in navigator && window.isSecureContext && 'PushManager' in window) {
    const status = document.querySelector('#push-status');
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
    const urlBase64ToUint8Array = (base64) => {
        const padding = '='.repeat((4 - (base64.length % 4)) % 4);
        const raw = window.atob((base64 + padding).replace(/-/g, '+').replace(/_/g, '/'));
        return Uint8Array.from([...raw].map((c) => c.charCodeAt(0)));
    };
    const post = (path, body) => fetch(path, {
        method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' }, body: JSON.stringify(body),
    });
    const refresh = async () => {
        const registration = await navigator.serviceWorker.ready;
        const subscription = await registration.pushManager.getSubscription();
        pushToggle.hidden = false;
        if (subscription) {
            status.textContent = 'Notifikasi push aktif di perangkat ini.';
            pushToggle.textContent = 'Nonaktifkan notifikasi push';
            pushToggle.onclick = async () => {
                await subscription.unsubscribe();
                await post('/notifications/unsubscribe', { endpoint: subscription.endpoint });
                refresh();
            };
        } else {
            status.textContent = 'Notifikasi push belum aktif di perangkat ini.';
            pushToggle.textContent = 'Aktifkan notifikasi push';
            pushToggle.onclick = async () => {
                try {
                    const sub = await registration.pushManager.subscribe({
                        userVisibleOnly: true, applicationServerKey: urlBase64ToUint8Array(pushToggle.dataset.vapidKey),
                    });
                    const json = sub.toJSON();
                    await post('/notifications/subscribe', { endpoint: json.endpoint, p256dh: json.keys.p256dh, auth: json.keys.auth, user_agent: navigator.userAgent.slice(0, 255) });
                    refresh();
                } catch {
                    status.textContent = 'Izin notifikasi ditolak atau tidak tersedia di perangkat ini.';
                }
            };
        }
    };
    refresh().catch(() => { status.textContent = 'Notifikasi push tidak tersedia di perangkat ini.'; });
}
