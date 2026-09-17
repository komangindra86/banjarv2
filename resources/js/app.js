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

const qrCanvas = document.querySelector('#website-qr');
if (qrCanvas) {
    import('qrcode').then(async ({ default: QRCode }) => {
        await QRCode.toCanvas(qrCanvas, qrCanvas.dataset.url, { width: 768, margin: 4, errorCorrectionLevel: 'M' });
        const download = document.querySelector('#qr-download');
        download.href = qrCanvas.toDataURL('image/png');
        download.hidden = false;
        document.querySelector('#qr-status').textContent = 'QR siap diunduh dan dicetak.';
    }).catch(() => {
        document.querySelector('#qr-status').textContent = 'QR belum dapat dibuat. Muat ulang halaman untuk mencoba kembali.';
    });
}

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
