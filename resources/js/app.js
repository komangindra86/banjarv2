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
