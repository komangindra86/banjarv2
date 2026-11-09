{{-- Tombol aktifkan push (resources/js/app.js #push-toggle). $compact: kartu ajakan di beranda portal, tersembunyi bila sudah aktif. --}}
<section class="card form-width mb-6" data-push-card @if($compact ?? false) data-hide-when-active hidden @endif>
<h2>{{ ($compact ?? false) ? 'Dapatkan pemberitahuan di HP' : 'Notifikasi push' }}</h2>
@if($compact ?? false)<p class="muted mt-2">Pengumuman, kegiatan, dan tagihan baru langsung muncul di HP Anda.</p>@endif
<p class="muted mt-2" id="push-status">Memeriksa status…</p>
<button type="button" class="button-secondary mt-4" id="push-toggle" data-vapid-key="{{ $vapidPublicKey }}" hidden>Aktifkan notifikasi push</button>
<noscript><p class="help">Aktifkan JavaScript untuk mengatur notifikasi push.</p></noscript>
</section>
