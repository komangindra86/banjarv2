<?php

namespace App\Http\Controllers;

use App\Modules\Website\ContentManager;
use App\Modules\Website\MediaManager;
use App\Modules\Website\PublicContent;
use App\Platform\Tenancy\TenantQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
        $activities = $type === 'pengumuman' ? $this->query->table('activities')->orderByDesc('starts_at')->get(['id', 'title']) : collect();
        $field = $type === 'dokumen' ? 'document' : 'photos';
        // Setelah gagal validasi, foto yang sudah terunggah tetap tampil (lihat save()).
        $ids = $request->session()->hasOldInput() ? (array) $request->old('keep_'.$field, []) : ($item ? ($type === 'dokumen' ? [$item->media_id] : $this->manager->photoIds($type, $item->id)) : []);
        $files = $this->files($ids);

        return view('admin.content.form', compact('type', 'definition', 'item', 'activities', 'field', 'files'));
    }

    public function save(Request $request, MediaManager $media, string $type, ?int $id = null)
    {
        $definition = $this->manager->authorize($request->user(), $type);
        $field = $type === 'dokumen' ? 'document' : 'photos';
        // Unggahan baru disimpan lebih dulu lalu digabung ke daftar foto, sehingga bila isian lain gagal divalidasi
        // foto tidak perlu dipilih ulang (berkasnya sudah masuk Semua foto & berkas).
        $new = $media->storeMany($request->user(), $request->file($field), $field, $type === 'dokumen');
        $kept = $type === 'dokumen' && $new ? [] : (array) $request->input('keep_'.$field, []);
        $request->merge(['keep_'.$field => [...$kept, ...$new]]);
        $this->manager->save($request->user(), $type, $id, $request->except($field));

        return redirect('/admin/content/'.$type)->with('success', $definition['label'].' “'.$request->input('title').'” disimpan.');
    }

    public function destroy(Request $request, string $type, int $id)
    {
        $this->manager->delete($request->user(), $type, $id);

        return redirect('/admin/content/'.$type)->with('success', 'Konten diarsipkan dan tidak ditampilkan di website.');
    }

    public function profile(Request $request)
    {
        $this->manager->authorize($request->user(), 'pengurus');

        $profile = $this->query->table('website_profiles')->first();
        $old = $request->session()->hasOldInput();

        return view('admin.content.profile', [
            'profile' => $profile,
            'logo' => $this->files([$old ? $request->old('keep_logo') : ($profile->logo_id ?? null)]),
            'cover' => $this->files([$old ? $request->old('keep_cover') : ($profile->cover_id ?? null)]),
        ]);
    }

    public function updateProfile(Request $request, MediaManager $media)
    {
        $this->manager->authorize($request->user(), 'pengurus');
        foreach (['logo', 'cover'] as $field) {
            $new = $media->storeMany($request->user(), $request->file($field), $field);
            if ($new) {
                $request->merge(['keep_'.$field => $new[0]]);
            }
            // Kolom ikut diubah hanya bila pemilih fotonya ada di formulir (foto dihapus = keep_* kosong).
            if ($request->has('keep_'.$field)) {
                $request->merge([$field.'_id' => $request->input('keep_'.$field)]);
            }
        }
        $this->manager->profile($request->user(), $request->except(['logo', 'cover']));

        return redirect('/admin/website')->with('success', 'Profil publik disimpan.');
    }

    /** Baris berkas untuk pratinjau, berurutan sesuai $ids (hanya milik Banjar ini). */
    private function files(array $ids): Collection
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        $rows = $ids ? $this->query->table('media_files')->whereIn('id', $ids)->get(['id', 'name', 'mime'])->keyBy('id') : collect();

        return collect($ids)->map(fn ($id) => $rows->get($id))->filter()->values();
    }

    public function media(Request $request)
    {
        $this->manager->authorize($request->user(), 'berita');

        return view('admin.content.media', ['items' => $this->query->table('media_files')->orderByDesc('id')->paginate(18)]);
    }

    public function upload(Request $request, MediaManager $media)
    {
        $ids = $media->upload($request->user(), $request->all());
        if (! $ids) {
            throw ValidationException::withMessages(['files' => 'Pilih minimal satu foto atau berkas PDF.']);
        }

        return back()->with('success', count($ids).' berkas diunggah.');
    }

    public function download(Request $request, int $id)
    {
        $this->authorizeMedia($request);
        $file = $this->query->table('media_files')->where('id', $id)->first();
        abort_unless($file && Storage::disk('local')->exists($file->path), 404);
        // ?lihat = pratinjau foto di formulir (tampil di halaman); tanpanya berkas diunduh.
        if ($request->has('lihat') && str_starts_with($file->mime, 'image/')) {
            return response()->file(Storage::disk('local')->path($file->path), ['Content-Type' => $file->mime, 'Cache-Control' => 'private, max-age=86400']);
        }

        return Storage::disk('local')->download($file->path, 'berkas-'.$id.'.'.pathinfo($file->path, PATHINFO_EXTENSION), ['Content-Type' => $file->mime]);
    }

    // Pengurus website maupun pengelola agenda perlu melihat pratinjau foto di formulirnya.
    private function authorizeMedia(Request $request): void
    {
        try {
            $this->manager->authorize($request->user(), 'berita');
        } catch (HttpException) {
            $this->manager->authorize($request->user(), 'agenda');
        }
    }

    public function qr(Request $request, PublicContent $public)
    {
        $this->manager->authorize($request->user(), 'berita');

        return view('admin.content.qr', ['websiteUrl' => $public->baseUrl()]);
    }
}
