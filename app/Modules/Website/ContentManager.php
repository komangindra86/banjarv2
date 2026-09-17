<?php

namespace App\Modules\Website;

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
        $input['slug'] = ($input['slug'] ?? null) ?: Str::slug(is_string($input['title'] ?? null) ? $input['title'] : '');
        $rules = [
            'title' => ['required', 'string', 'max:180'],
            'slug' => ['bail', 'required', 'string', 'max:180', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique($definition['table'])->where('banjar_id', $this->tenant->id())->ignore($id)],
            'body' => ['nullable', 'string', 'max:50000'],
            'status' => ['required', Rule::in(['DRAFT', 'PUBLISHED', 'ARCHIVED'])],
            'published_at' => ['nullable', 'date'],
            'media_id' => ['nullable', 'integer', $this->mediaRule($type !== 'dokumen')],
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
        if ($type === 'galeri') {
            $rules += ['gallery_media' => ['nullable', 'array', 'max:50'], 'gallery_media.*' => ['integer', 'distinct', $this->mediaRule()]];
        }
        // A blank publication date means publish now; timezone matches form labels (WITA).
        $input['published_at'] = ($input['published_at'] ?? null) ?: now()->timezone('Asia/Makassar')->toDateTimeString();
        $data = Validator::make($input, $rules, [], ['slug' => 'Alamat singkat'])->validate();
        $gallery = $data['gallery_media'] ?? [];
        unset($data['gallery_media']);
        foreach (['published_at', 'expires_at', 'starts_at', 'ends_at'] as $date) {
            if (! empty($data[$date])) {
                $data[$date] = Carbon::parse($data[$date], 'Asia/Makassar')->utc()->format('Y-m-d H:i:s');
            }
        }

        return DB::transaction(function () use ($definition, $actor, $id, $data, $gallery, $type) {
            $old = $id ? $this->query->table($definition['table'])->where('id', $id)->lockForUpdate()->first() : null;
            abort_if($id && ! $old, 404);
            if ($id) {
                $this->query->table($definition['table'])->where('id', $id)->update($data + ['updated_at' => now()]);
            } else {
                $id = DB::table($definition['table'])->insertGetId($data + ['banjar_id' => $this->tenant->id(), 'created_at' => now(), 'updated_at' => now()]);
            }
            if ($type === 'galeri') {
                $this->query->table('gallery_items')->where('gallery_id', $id)->delete();
                foreach ($gallery as $order => $media) {
                    DB::table('gallery_items')->insert(['banjar_id' => $this->tenant->id(), 'gallery_id' => $id, 'media_id' => $media, 'sort_order' => $order]);
                }
            }
            $this->audit->record($this->tenant->id(), $actor->id, $old ? 'content.updated' : 'content.created', $definition['table'], $id, (array) $old, $data);

            return $id;
        });
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
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'], 'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
            'logo_id' => ['nullable', 'integer', $this->mediaRule()], 'cover_id' => ['nullable', 'integer', $this->mediaRule()],
        ])->validate();
        DB::transaction(function () use ($actor, $data) {
            DB::table('website_profiles')->updateOrInsert(['banjar_id' => $this->tenant->id()], $data + ['updated_at' => now()]);
            $this->audit->record($this->tenant->id(), $actor->id, 'profile.updated', 'website_profiles', $this->tenant->id());
        });
    }
}
