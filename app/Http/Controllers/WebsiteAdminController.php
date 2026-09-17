<?php

namespace App\Http\Controllers;

use App\Modules\Website\ContentManager;
use App\Modules\Website\MediaManager;
use App\Modules\Website\PublicContent;
use App\Platform\Tenancy\TenantQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

final class WebsiteAdminController extends Controller
{
    public function __construct(private TenantQuery $query, private ContentManager $manager) {}

    public function index(Request $request, string $type)
    {
        $definition = $this->manager->authorize($request->user(), $type);
        $search = mb_substr((string) $request->query('q', ''), 0, 180);
        $items = $this->query->table($definition['table'])->when($search, fn ($q) => $q->where('title', 'like', '%'.$search.'%'))->orderByDesc('updated_at')->paginate(15)->withQueryString();

        return view('admin.content.index', compact('type', 'definition', 'items', 'search'));
    }

    public function edit(Request $request, string $type, ?int $id = null)
    {
        $definition = $this->manager->authorize($request->user(), $type);
        $item = $id ? $this->query->table($definition['table'])->where('id', $id)->first() : null;
        abort_if($id && ! $item, 404);
        $media = $this->query->table('media_files')->when($type !== 'dokumen', fn ($q) => $q->where('mime', 'like', 'image/%'))->orderByDesc('id')->get();
        $activities = $type === 'pengumuman' ? $this->query->table('activities')->orderByDesc('starts_at')->get(['id', 'title']) : collect();
        $selected = $type === 'galeri' && $id ? $this->query->table('gallery_items')->where('gallery_id', $id)->orderBy('sort_order')->pluck('media_id')->all() : [];

        return view('admin.content.form', compact('type', 'definition', 'item', 'media', 'activities', 'selected'));
    }

    public function save(Request $request, string $type, ?int $id = null)
    {
        $id = $this->manager->save($request->user(), $type, $id, $request->all());

        return redirect('/admin/content/'.$type.'/'.$id.'/edit')->with('success', 'Konten disimpan.');
    }

    public function destroy(Request $request, string $type, int $id)
    {
        $this->manager->delete($request->user(), $type, $id);

        return redirect('/admin/content/'.$type)->with('success', 'Konten diarsipkan dan tidak ditampilkan di website.');
    }

    public function profile(Request $request)
    {
        $this->manager->authorize($request->user(), 'pengurus');

        return view('admin.content.profile', ['profile' => $this->query->table('website_profiles')->first(), 'media' => $this->query->table('media_files')->where('mime', 'like', 'image/%')->orderByDesc('id')->get()]);
    }

    public function updateProfile(Request $request)
    {
        $this->manager->profile($request->user(), $request->all());

        return back()->with('success', 'Profil publik disimpan.');
    }

    public function media(Request $request)
    {
        $this->manager->authorize($request->user(), 'berita');

        return view('admin.content.media', ['items' => $this->query->table('media_files')->orderByDesc('id')->paginate(18)]);
    }

    public function upload(Request $request, MediaManager $media)
    {
        $media->upload($request->user(), $request->all());

        return back()->with('success', 'Berkas diunggah. Pilih berkas ini pada formulir konten atau profil.');
    }

    public function visibility(Request $request, int $id, MediaManager $media)
    {
        $media->visibility($request->user(), $id, $request->all());

        return back()->with('success', 'Akses berkas diperbarui.');
    }

    public function download(Request $request, int $id)
    {
        $this->manager->authorize($request->user(), 'berita');
        $file = $this->query->table('media_files')->where('id', $id)->first();
        abort_unless($file && Storage::disk('local')->exists($file->path), 404);

        return Storage::disk('local')->download($file->path, 'berkas-'.$id.'.'.pathinfo($file->path, PATHINFO_EXTENSION), ['Content-Type' => $file->mime]);
    }

    public function qr(Request $request, PublicContent $public)
    {
        $this->manager->authorize($request->user(), 'berita');

        return view('admin.content.qr', ['websiteUrl' => $public->baseUrl()]);
    }
}
