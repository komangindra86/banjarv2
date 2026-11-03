<?php

namespace App\Platform\Tenants;

use App\Models\User;
use App\Platform\Audit\Audit;
use App\Platform\Identity\Access;
use App\Platform\Modules\ModuleGate;
use App\Platform\Tenancy\CurrentBanjar;
use App\Platform\Tenancy\TenantQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class SettingsManager
{
    // Template website publik (semua bernuansa Bali). accent = warna aksen yang serasi, swatches = pratinjau palet.
    public const TEMPLATES = [
        'template-01' => ['name' => 'Bali Santih', 'note' => 'Editorial gading, tinta, dan garis emas.', 'accent' => '#b94715', 'swatches' => ['#fbf7f0', '#231d18', '#9c7a45']],
        'template-02' => ['name' => 'Subak', 'note' => 'Hijau sawah berundak dan emas padi.', 'accent' => '#2f6b3a', 'swatches' => ['#f4f6ec', '#1f2a1c', '#a3843f']],
        'template-03' => ['name' => 'Poleng', 'note' => 'Kotak hitam-putih kain poleng, tegas dan kontras.', 'accent' => '#a3201b', 'swatches' => ['#fafaf8', '#111111', '#b8892b']],
        'template-04' => ['name' => 'Prada', 'note' => 'Merah marun dan hiasan emas prada, anggun dan berwibawa.', 'accent' => '#8c1c22', 'swatches' => ['#fbf5ec', '#3a0f12', '#c39236']],
        'template-05' => ['name' => 'Segara', 'note' => 'Biru laut dan pasir pantai, ringan dan lapang.', 'accent' => '#0f6e8c', 'swatches' => ['#f6fafb', '#0c2233', '#b08b4f']],
    ];

    public function __construct(private Access $access, private ModuleGate $modules, private CurrentBanjar $tenant, private TenantQuery $query, private Audit $audit) {}

    public function update(User $actor, int $id, array $input): void
    {
        $this->access->requireTenant($actor, 'settings.manage');
        $this->modules->authorize('WEBSITE');
        $data = Validator::make($input, ['template_code' => ['required', Rule::in(array_keys(self::TEMPLATES))], 'accent_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/']])->validate();
        DB::transaction(function () use ($actor, $id, $data) {
            $old = $this->query->table('banjar_settings')->where('id', $id)->lockForUpdate()->first();
            abort_unless($old, 404);
            $this->query->table('banjar_settings')->where('id', $id)->update($data + ['updated_at' => now()]);
            $this->audit->record($this->tenant->id(), $actor->id, 'settings.updated', 'settings', $id, (array) $old, $data);
        });
    }
}
