<?php

namespace App\Http\Controllers;

use App\Modules\Website\ContentCatalog;
use App\Modules\Website\PublicContent;
use App\Platform\Modules\ModuleGate;
use App\Platform\Tenancy\CurrentBanjar;
use App\Platform\Tenancy\TenantQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class PublicWebsiteController extends Controller
{
    public function __construct(private TenantQuery $query, private CurrentBanjar $tenant, private PublicContent $public, private ModuleGate $gate) {}

    private function page(string $view, array $data = [])
    {
        abort_unless($this->tenant->optional() && $this->gate->allows('WEBSITE'), 404);
        $profile = $this->query->table('website_profiles')->first();
        $baseUrl = $this->public->baseUrl();

        return view($view, $data + [
            'profile' => $profile, 'settings' => $this->query->table('banjar_settings')->first(), 'publicContent' => $this->public,
            'baseUrl' => $baseUrl, 'canonical' => $baseUrl.(request()->path() === '/' ? '' : '/'.request()->path()),
            'seoTitle' => $this->tenant->get()->name, 'seoDescription' => Str::limit($profile->description ?? 'Informasi, pengumuman, agenda, dan layanan '.$this->tenant->get()->name, 160),
            'seoImage' => $this->public->image($profile->cover_id ?? $profile->logo_id ?? null),
        ]);
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
        ]);
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

        return $this->page('public.index', ['type' => $type, 'definition' => $definition, 'archive' => $archive, 'items' => $items->orderByDesc('published_at')->paginate(12)->withQueryString(), 'seoTitle' => $definition['label']]);
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

        return $this->page('public.show', compact('type', 'definition', 'item', 'photos', 'related', 'document') + ['seoTitle' => $item->title, 'seoDescription' => Str::limit($item->body ?? $item->title, 160), 'seoImage' => $this->public->image($item->media_id)]);
    }

    public function profile()
    {
        abort_unless($this->tenant->optional(), 404);

        return $this->page('public.profile', ['officials' => $this->public->published('pengurus')->orderBy('sort_order')->get(), 'seoTitle' => 'Profil Banjar']);
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

    public function sitemap()
    {
        abort_unless($this->tenant->optional() && $this->gate->allows('WEBSITE'), 404);
        $base = $this->public->baseUrl();
        $urls = [$base, $base.'/profil'];
        foreach (ContentCatalog::TYPES as $type => $definition) {
            if ($this->gate->allows(ContentCatalog::get($type)['module'])) {
                $urls[] = $base.'/'.$type;
                foreach ($this->public->published($type)->orderBy('id')->cursor() as $item) {
                    $urls[] = $base.'/'.$type.'/'.$item->slug;
                }
            }
        }

        return response()->view('public.sitemap', compact('urls'))->header('Content-Type', 'application/xml; charset=UTF-8');
    }
}
