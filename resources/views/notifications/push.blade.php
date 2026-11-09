{{-- Pengaturan push di halaman Notifikasi (resources/js/app.js #push-toggle). --}}
<section class="card form-width mb-6">
<h2>Notifikasi push</h2>
<p class="muted mt-2" id="push-status">Memeriksa status…</p>
<button type="button" class="button-secondary mt-4" id="push-toggle" data-vapid-key="{{ $vapidPublicKey }}" hidden>Aktifkan notifikasi push</button>
<noscript><p class="help">Aktifkan JavaScript untuk mengatur notifikasi push.</p></noscript>
</section>
