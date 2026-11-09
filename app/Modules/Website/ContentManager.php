<?php

namespace App\Modules\Website;

use App\Modules\Notification\NotifyWarga;
use App\Models\User;
use App\Platform\Audit\Audit;
use App\Platform\Identity\Access;
use App\Platform\Modules\ModuleGate;
use App\Platform\Tenancy\CurrentBanjar;
use App\Platform\Tenancy\TenantQuery;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class ContentManager
{
    public function __construct(private TenantQuery $query, private CurrentBanjar $tenant, private Access $access, private ModuleGate $gate, private Audit $audit) {}

    // Hanya tautan Google Maps (https): maps.app.goo.gl/…, goo.gl/maps/…, google.<tld>/maps/…, maps.google.<tld>/….
    public static function isMapsUrl(string $url): bool
    {
        $parts = parse_url(trim($url));
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '/';

        return $host === 'maps.app.goo.gl'
            || ($host === 'goo.gl' && str_starts_with($path, '/maps'))
            || (bool) preg_match('/^maps\.google\.[a-z]{2,3}(\.[a-z]{2})?$/', $host)
            || (preg_match('/^(www\.)?google\.[a-z]{2,3}(\.[a-z]{2})?$/', $host) && str_starts_with($path, '/maps'));
    }

    // Contoh kalimat profil yang bisa dipakai lalu disesuaikan; [teks dalam kurung] wajib diganti pengurus.
    public static function profileTemplates(string $banjar): array
    {
        return [
            'tagline' => "Om Swastiastu. Selamat datang di website resmi {$banjar}, wadah informasi, kegiatan, dan layanan bagi seluruh krama.",
            'description' => "{$banjar} adalah banjar adat di wilayah Desa Adat [nama desa adat], Kecamatan [nama kecamatan], Kabupaten [nama kabupaten], Bali. Banjar menjadi pusat kehidupan adat, sosial, dan keagamaan krama, berlandaskan Tri Hita Karana: hubungan harmonis dengan Ida Sang Hyang Widhi Wasa (parahyangan), sesama krama (pawongan), dan alam lingkungan (palemahan).\n\nSaat ini {$banjar} mewadahi sekitar [jumlah] kepala keluarga yang bersama-sama ngayah, mengikuti paruman, serta menyelenggarakan upacara di pura dan bale banjar. Kegiatan banjar dipimpin oleh Kelian Adat bersama para prajuru: Penyarikan, Petengen, dan Kesinoman.\n\nMelalui website ini, krama dapat mengikuti pengumuman, jadwal kegiatan, laporan, dan layanan banjar dengan lebih mudah.",
            'history' => "{$banjar} berdiri sekitar tahun [tahun berdiri], berawal dari [jumlah] keluarga yang menetap di wilayah [nama wilayah] dan sepakat membentuk satu kesatuan banjar adat. Nama [nama banjar] berasal dari [asal-usul nama, misalnya nama tempat, pohon, atau peristiwa].\n\nBale banjar pertama dibangun pada tahun [tahun] secara gotong royong oleh krama, kemudian dipugar pada tahun [tahun]. Seiring waktu, jumlah krama terus bertambah dan berbagai kegiatan adat, seni, dan kepemudaan (sekaa teruna) tumbuh di banjar ini.\n\nHingga kini, {$banjar} tetap menjaga awig-awig dan pararem yang diwariskan para pendahulu, sambil terus beradaptasi dengan perkembangan zaman demi kesejahteraan seluruh krama.",
        ];
    }

    public function authorize(User $actor, string $type): array
    {
        $definition = ContentCatalog::get($type);
        $this->access->requireTenant($actor, $definition['permission']);
        $this->gate->authorize($definition['module']);

        return $definition;
    }

    private function mediaRule(bool $image = true): mixed
    {
        return Rule::exists('media_files', 'id')->where(function ($q) use ($image) {
            $q->where('banjar_id', $this->tenant->id());
            if ($image) {
                $q->whereIn('mime', ['image/jpeg', 'image/png', 'image/webp']);
            }
        });
    }

    public function save(User $actor, string $type, ?int $id, array $input): int
    {
        $definition = $this->authorize($actor, $type);
        if ($id) {
            abort_unless($this->query->table($definition['table'])->where('id', $id)->exists(), 404);
        }
        // §58: alamat halaman tidak diisi pengurus; dibuat otomatis dari judul saat konten dibuat (lihat slug()).
        unset($input['slug']);
        $rules = [
            'title' => ['required', 'string', 'max:180'],
            'body' => ['nullable', 'string', 'max:50000'],
            'status' => ['required', Rule::in(['DRAFT', 'PUBLISHED', 'ARCHIVED'])],
            'published_at' => ['nullable', 'date'],
        ];
        if ($type === 'pengumuman') {
            $rules += ['priority' => ['required', Rule::in(['NORMAL', 'IMPORTANT'])], 'expires_at' => ['nullable', 'date', 'after:published_at'], 'related_activity_id' => ['nullable', 'integer', Rule::exists('activities', 'id')->where('banjar_id', $this->tenant->id())]];
        }
        if ($type === 'agenda') {
            $rules += ['starts_at' => ['required', 'date'], 'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'], 'location' => ['nullable', 'string', 'max:255']];
        }
        if ($type === 'pengurus') {
            $rules += ['position' => ['required', 'string', 'max:120'], 'sort_order' => ['required', 'integer', 'min:0', 'max:10000']];
        }
        if ($type === 'layanan') {
            $rules += ['requirements' => ['nullable', 'string', 'max:10000'], 'procedure' => ['nullable', 'string', 'max:10000'], 'contact' => ['nullable', 'string', 'max:255']];
        }
        // Foto (atau berkas dokumen) = berkas lama yang dipertahankan + unggahan baru, berurutan; yang pertama
        // menjadi foto utama (media_id). Berkas unggahan sudah disimpan oleh controller lewat MediaManager.
        $document = $type === 'dokumen';
        $field = $document ? 'document' : 'photos';
        $input[$field] = array_values(array_filter((array) ($input['keep_'.$field] ?? []), fn ($id) => $id !== null && $id !== ''));
        $rules += [$field => ['array', 'max:'.($document ? 1 : 50)], $field.'.*' => ['integer', 'distinct', $this->mediaRule(! $document)]];
        // A blank publication date means publish now; timezone matches form labels (WITA).
        $input['published_at'] = ($input['published_at'] ?? null) ?: now()->timezone('Asia/Makassar')->toDateTimeString();
        $data = Validator::make($input, $rules, [$field.'.max' => 'Paling banyak 50 foto untuk satu konten.'])->validate();
        $photos = array_map('intval', $data[$field]);
        unset($data[$field]);
        $data['media_id'] = $photos[0] ?? null;
        foreach (['published_at', 'expires_at', 'starts_at', 'ends_at'] as $date) {
            if (! empty($data[$date])) {
                $data[$date] = Carbon::parse($data[$date], 'Asia/Makassar')->utc()->format('Y-m-d H:i:s');
            }
        }

        return DB::transaction(function () use ($definition, $actor, $id, $data, $photos, $type) {
            $old = $id ? $this->query->table($definition['table'])->where('id', $id)->lockForUpdate()->first() : null;
            abort_if($id && ! $old, 404);
            if ($id) {
                $this->query->table($definition['table'])->where('id', $id)->update($data + ['updated_at' => now()]);
            } else {
                $data['slug'] = $this->slug($definition['table'], $data['title'], $type);
                $id = DB::table($definition['table'])->insertGetId($data + ['banjar_id' => $this->tenant->id(), 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->syncPhotos($type, $id, $photos);
            $this->audit->record($this->tenant->id(), $actor->id, $old ? 'content.updated' : 'content.created', $definition['table'], $id, (array) $old, $data + ['photos' => $photos]);
            // Pengumuman/kegiatan yang langsung terbit diberitahukan ke warga lewat antrean; yang terjadwal oleh scheduler.
            if (in_array($type, ['pengumuman', 'agenda'], true) && $data['status'] === 'PUBLISHED') {
                NotifyWarga::dispatch('content', $this->tenant->id())->afterCommit();
            }

            return $id;
        });
    }

    /** Foto konten berurutan; konten lama yang hanya punya foto utama tetap menampilkan foto itu. @return list<int> */
    public function photoIds(string $type, int $id): array
    {
        $definition = ContentCatalog::get($type);
        $main = $this->query->table($definition['table'])->where('id', $id)->value('media_id');
        $ids = match ($type) {
            'dokumen' => [],
            'galeri' => $this->query->table('gallery_items')->where('gallery_id', $id)->orderBy('sort_order')->orderBy('id')->pluck('media_id')->all(),
            default => $this->query->table('content_photos')->where('content_type', $type)->where('content_id', $id)->orderBy('sort_order')->orderBy('id')->pluck('media_id')->all(),
        };

        return array_values(array_unique(array_map('intval', $main ? [$main, ...$ids] : $ids)));
    }

    private function syncPhotos(string $type, int $id, array $photos): void
    {
        // Foto yang dipasang pada konten memang untuk website; berkas Privat lama otomatis menjadi Publik.
        if ($photos) {
            $this->query->table('media_files')->whereIn('id', $photos)->where('visibility', 'PRIVATE')->update(['visibility' => 'PUBLIC', 'updated_at' => now()]);
        }
        if ($type === 'dokumen') {
            return;
        }
        [$table, $key, $extra] = $type === 'galeri' ? ['gallery_items', 'gallery_id', []] : ['content_photos', 'content_id', ['content_type' => $type]];
        $this->query->table($table)->where($key, $id)->where($extra)->delete();
        foreach ($photos as $order => $media) {
            DB::table($table)->insert(['banjar_id' => $this->tenant->id(), $key => $id, 'media_id' => $media, 'sort_order' => $order] + $extra);
        }
    }

    // Alamat dari judul ("Sangkep Rutin" → sangkep-rutin), diberi nomor bila kembar. Tidak ikut berubah saat judul
    // diedit supaya tautan yang sudah dibagikan ke WhatsApp tetap berfungsi.
    private function slug(string $table, string $title, string $fallback): string
    {
        $base = rtrim(Str::limit(Str::slug($title), 170, ''), '-') ?: $fallback;
        $slug = $base;
        for ($n = 2; $this->query->table($table)->where('slug', $slug)->exists(); $n++) {
            $slug = $base.'-'.$n;
        }

        return $slug;
    }

    public function delete(User $actor, string $type, int $id): void
    {
        $definition = $this->authorize($actor, $type);
        DB::transaction(function () use ($definition, $actor, $id) {
            $old = $this->query->table($definition['table'])->where('id', $id)->lockForUpdate()->first();
            abort_unless($old, 404);
            // Archive preserves permalinks/slugs and future activity references.
            $this->query->table($definition['table'])->where('id', $id)->update(['status' => 'ARCHIVED', 'updated_at' => now()]);
            $this->audit->record($this->tenant->id(), $actor->id, 'content.archived', $definition['table'], $id, (array) $old, ['status' => 'ARCHIVED']);
        });
    }

    public function profile(User $actor, array $input): void
    {
        $this->authorize($actor, 'pengurus');
        $data = Validator::make($input, [
            'tagline' => ['nullable', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:10000'], 'history' => ['nullable', 'string', 'max:20000'],
            'address' => ['nullable', 'string', 'max:2000'], 'phone' => ['nullable', 'regex:/^[+0-9 ()-]{6,30}$/'], 'email' => ['nullable', 'email', 'max:255'],
            'maps_url' => ['nullable', 'string', 'max:500', function ($attribute, $value, $fail) {
                if (! self::isMapsUrl((string) $value)) {
                    $fail('Tempel tautan dari Google Maps (tombol Bagikan → Salin link).');
                }
            }],
            'logo_id' => ['nullable', 'integer', $this->mediaRule()], 'cover_id' => ['nullable', 'integer', $this->mediaRule()],
        ])->validate();
        DB::transaction(function () use ($actor, $data) {
            // Logo dan sampul selalu untuk tampilan publik (tanpa pilihan Publik/Privat).
            $photos = array_filter([$data['logo_id'] ?? null, $data['cover_id'] ?? null]);
            if ($photos) {
                $this->query->table('media_files')->whereIn('id', $photos)->update(['visibility' => 'PUBLIC', 'updated_at' => now()]);
            }
            DB::table('website_profiles')->updateOrInsert(['banjar_id' => $this->tenant->id()], $data + ['updated_at' => now()]);
            $this->audit->record($this->tenant->id(), $actor->id, 'profile.updated', 'website_profiles', $this->tenant->id());
        });
    }
}
