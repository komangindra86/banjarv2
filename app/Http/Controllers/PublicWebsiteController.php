<?php

namespace App\Http\Controllers;

use App\Modules\Website\BrandIcon;
use App\Modules\Website\ContentCatalog;
use App\Modules\Website\PublicContent;
use App\Modules\Website\StructuredData;
use App\Platform\Modules\ModuleGate;
use App\Platform\Tenancy\CurrentBanjar;
use App\Platform\Tenancy\TenantQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class PublicWebsiteController extends Controller
{
    public function __construct(private TenantQuery $query, private CurrentBanjar $tenant, private PublicContent $public, private ModuleGate $gate) {}

    /** @param  null|callable(StructuredData): string  $schema  data terstruktur Google untuk halaman ini */
    private function page(string $view, array $data = [], ?callable $schema = null)
    {
        abort_unless($this->tenant->optional() && $this->gate->allows('WEBSITE'), 404);
        $profile = $this->query->table('website_profiles')->first();
        $baseUrl = $this->public->baseUrl();
        $banjar = $this->tenant->get();
        // Halaman bernomor dan riwayat pengumuman punya alamat kanonis sendiri agar Google tetap menelusuri isinya.
        $query = array_filter(['arsip' => request()->query('arsip') === '1' ? '1' : null, 'page' => (int) request()->query('page') > 1 ? (int) request()->query('page') : null]);
        $logo = $this->public->image($profile->logo_id ?? null) ? app(BrandIcon::class)->url('icon-512') : null;
        $structured = new StructuredData($baseUrl, $banjar, $profile, $logo);

        return view($view, $data + [
            'profile' => $profile, 'settings' => $this->query->table('banjar_settings')->first(), 'publicContent' => $this->public,
            'baseUrl' => $baseUrl, 'canonical' => $baseUrl.(request()->path() === '/' ? '' : '/'.request()->path()).($query ? '?'.http_build_query($query) : ''),
            'seoTitle' => $banjar->name, 'seoDescription' => self::summary($profile->description ?? 'Website resmi '.$banjar->name.': pengumuman, kegiatan, berita, dan layanan untuk krama.'),
            'seoImage' => $this->public->image($profile->cover_id ?? $profile->logo_id ?? null),
            'jsonLd' => $schema ? $schema($structured) : null,
            // Banjar demo diisi bebas oleh reviewer dan dipulihkan tiap malam: jangan sampai masuk hasil pencarian.
            'noindex' => (int) config('platform.demo_banjar_id') === (int) $banjar->id,
        ]);
    }

    private static function summary(string $text): string
    {
        return Str::limit(trim(preg_replace('/\s+/u', ' ', $text)), 160);
    }

    public function home(Request $request)
    {
        if ($request->attributes->get('platform')) {
            return redirect('/login');
        }

        return $this->page('public.home', [
            'announcements' => $this->public->published('pengumuman')->orderByRaw("priority = 'IMPORTANT' desc")->orderByDesc('published_at')->limit(3)->get(),
            'activities' => $this->public->published('agenda')->where('starts_at', '>=', now())->orderBy('starts_at')->limit(3)->get(),
            'services' => $this->public->published('layanan')->orderBy('title')->limit(4)->get(),
            'news' => $this->public->published('berita')->orderByDesc('published_at')->limit(3)->get(),
        ], fn (StructuredData $s) => $s->site());
    }

    public function index(Request $request, string $type)
    {
        $definition = ContentCatalog::get($type);
        abort_unless($this->tenant->optional() && $this->gate->allows($definition['module']), 404);
        $archive = $type === 'pengumuman' && $request->query('arsip') === '1';
        $items = $this->public->published($type, ! $archive);
        if ($archive) {
            $items->where('expires_at', '<=', now());
        }
        if ($type === 'pengurus') {
            $items->orderBy('sort_order');
        } elseif ($type === 'agenda') {
            $items->orderByDesc('starts_at');
        }

        return $this->page('public.index', ['type' => $type, 'definition' => $definition, 'archive' => $archive, 'items' => $items->orderByDesc('published_at')->paginate(12)->withQueryString(),
            'seoTitle' => $archive ? 'Riwayat pengumuman' : $definition['label'], 'seoDescription' => self::summary($definition['lead'].' '.$this->tenant->get()->name.'.')],
            fn (StructuredData $s) => $s->listing($type, $definition['label']));
    }

    public function show(string $type, string $slug)
    {
        $definition = ContentCatalog::get($type);
        abort_unless($this->tenant->optional(), 404);
        $item = $this->public->published($type, false)->where('slug', $slug)->first();
        abort_unless($item, 404);
        // Galeri menampilkan semua fotonya; konten lain menampilkan foto utama besar lalu foto lainnya di bawah isi.
        $photos = match ($type) {
            'galeri' => $this->query->table('gallery_items')->where('gallery_id', $item->id)->orderBy('sort_order')->get(),
            'dokumen' => collect(),
            default => $this->query->table('content_photos')->where('content_type', $type)->where('content_id', $item->id)->where('media_id', '!=', $item->media_id ?? 0)->orderBy('sort_order')->get(),
        };
        $related = $type === 'pengumuman' && $item->related_activity_id ? $this->public->published('agenda')->where('id', $item->related_activity_id)->first() : null;
        $document = $type === 'dokumen' && $item->media_id ? $this->query->table('media_files')->where('id', $item->media_id)->where('visibility', 'PUBLIC')->first() : null;

        // Tautan ke tulisan lain sejenis: membantu warga membaca lebih banyak dan Google menemukan semua tulisan.
        $more = in_array($type, ['berita', 'pengumuman', 'agenda', 'galeri'], true)
            ? $this->public->published($type)->where('id', '!=', $item->id)->orderByDesc('published_at')->limit(3)->get() : collect();
        $images = collect([$item->media_id])->merge($photos->pluck('media_id'))->map(fn ($id) => $this->public->image($id))->filter()->unique()->take(10)->all();

        return $this->page('public.show', compact('type', 'definition', 'item', 'photos', 'related', 'document', 'more') + ['seoTitle' => $item->title, 'seoDescription' => self::summary($item->body ?: $item->title.' — '.$definition['label'].' '.$this->tenant->get()->name.'.'), 'seoImage' => $this->public->image($item->media_id)],
            fn (StructuredData $s) => $s->item($type, $definition['label'], $item, $this->public->baseUrl().'/'.$type.'/'.$item->slug, $images));
    }

    public function profile()
    {
        abort_unless($this->tenant->optional(), 404);

        return $this->page('public.profile', ['officials' => $this->public->published('pengurus')->orderBy('sort_order')->get(), 'seoTitle' => 'Profil Banjar'], fn (StructuredData $s) => $s->site());
    }

    public function media(int $id)
    {
        abort_unless($this->tenant->optional(), 404);
        $file = $this->query->table('media_files')->where('id', $id)->where('visibility', 'PUBLIC')->first();
        abort_unless($file && $this->public->mediaVisible($id) && Storage::disk('local')->exists($file->path), 404);
        $name = 'berkas-'.$id.'.'.pathinfo($file->path, PATHINFO_EXTENSION);
        if ($file->mime === 'application/pdf') {
            return Storage::disk('local')->download($file->path, $name, ['Content-Type' => $file->mime, 'Content-Security-Policy' => "sandbox; default-src 'none'"]);
        }

        return response()->file(Storage::disk('local')->path($file->path), ['Content-Type' => $file->mime]);
    }

    // Peta situs untuk Google: setiap tulisan terbit beserta tanggal terakhir diubah dan foto utamanya.
    public function sitemap()
    {
        abort_unless($this->tenant->optional() && $this->gate->allows('WEBSITE'), 404);
        $base = $this->public->baseUrl();
        $urls = [['loc' => $base.'/', 'lastmod' => null, 'image' => null], ['loc' => $base.'/profil', 'lastmod' => null, 'image' => null]];
        foreach (ContentCatalog::TYPES as $type => $definition) {
            if ($this->gate->allows(ContentCatalog::get($type)['module'])) {
                $urls[] = ['loc' => $base.'/'.$type, 'lastmod' => null, 'image' => null];
                foreach ($this->public->published($type)->orderBy('id')->cursor() as $item) {
                    $image = $this->public->image($item->media_id);
                    $urls[] = ['loc' => $base.'/'.$type.'/'.$item->slug, 'lastmod' => \Carbon\Carbon::parse($item->updated_at ?? $item->published_at)->toAtomString(), 'image' => $image ? $base.$image : null];
                }
            }
        }

        return response()->view('public.sitemap', compact('urls'))->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    // robots.txt per alamat: website Banjar boleh ditelusuri (kecuali halaman pengurus/akun) dan menunjuk peta situsnya;
    // dashboard platform, portal krama, dan Banjar tanpa website tidak untuk mesin pencari.
    public function robots()
    {
        $lines = ['User-agent: *'];
        if ($this->tenant->optional() && $this->gate->allows('WEBSITE')) {
            foreach (['/admin', '/login', '/logout', '/portal', '/akun', '/payments', '/aktivitas', '/notifications', '/pilih-banjar'] as $path) {
                $lines[] = 'Disallow: '.$path;
            }
            $lines[] = 'Allow: /';
            if ((int) config('platform.demo_banjar_id') !== (int) $this->tenant->get()->id) {
                $lines[] = '';
                $lines[] = 'Sitemap: '.$this->public->baseUrl().'/sitemap.xml';
            }
        } else {
            $lines[] = 'Disallow: /';
        }

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
