{{-- Ajakan mengaktifkan push di beranda portal (resources/js/app.js data-push-invite): muncul hanya bila push belum aktif
     di perangkat ini; "Nanti saja" menunda 30 hari dan tidak pernah tampil lebih dari sekali per kunjungan. --}}
<dialog class="card form-width" data-push-invite data-vapid-key="{{ $vapidPublicKey }}" aria-labelledby="push-invite-title">
    <h2 id="push-invite-title">Dapatkan pemberitahuan di HP?</h2>
    <p class="muted mt-2">Pengumuman, kegiatan, dan tagihan baru dari {{ $currentBanjar->name }} langsung muncul di HP Anda, tanpa perlu membuka aplikasi.</p>
    <p class="help mt-2" data-push-invite-status role="status"></p>
    <div class="flex flex-wrap gap-3 mt-5">
        <button type="button" class="button" data-push-invite-accept>Ya, aktifkan</button>
        <button type="button" class="button-secondary" data-push-invite-later>Nanti saja</button>
    </div>
</dialog>
