<nav class="flex flex-wrap gap-2 mb-7" aria-label="Menu surat">
    @if($portal)<a class="button-secondary" href="/portal/beranda">← Beranda</a>@endif
    <a class="button-secondary" href="{{ $portal ? '/portal' : '/admin/letters' }}">{{ $portal ? 'Surat saya' : 'Pengajuan' }}</a>
    <a class="button-secondary" href="{{ $portal ? '/portal/surat' : '/admin/letters' }}?status=ISSUED">Arsip terbit</a>
    @if($portal)<a class="button-secondary" href="/portal/layanan">Layanan surat</a>
    @elseif(app(\App\Platform\Identity\Access::class)->tenant(auth()->user(), 'letter.configure'))<a class="button-secondary" href="/admin/letters/services">Aturan layanan</a>@endif
</nav>
