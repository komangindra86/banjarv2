<?php

namespace App\Modules\Website;

use App\Platform\Modules\ModuleGate;
use App\Platform\Tenancy\CurrentBanjar;
use App\Platform\Tenancy\TenantQuery;
use Illuminate\Database\Query\Builder;

final class PublicContent
{
    public function __construct(private TenantQuery $query, private CurrentBanjar $tenant, private ModuleGate $gate) {}

    public function published(string $type, bool $active = true): Builder
    {
        $definition = ContentCatalog::get($type);
        $q = $this->query->table($definition['table'])->where('status', 'PUBLISHED')->where('published_at', '<=', now());
        if (! $this->gate->allows('WEBSITE') || ! $this->gate->allows($definition['module'])) {
            $q->whereRaw('1 = 0');
        }
        if ($type === 'agenda') {
            // Internal activities (for example a closed paruman) stay off the public website.
            $q->where('visibility', 'PUBLIC');
        }
        if ($type === 'pengumuman' && $active) {
            $q->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
        }

        return $q;
    }

    public function baseUrl(): string
    {
        $domain = $this->query->table('tenant_domains')->where('id', $this->tenant->get()->primary_domain_id)
            ->where('verification_status', 'VERIFIED')->whereNotNull('verified_at')->value('domain');
        abort_unless($domain, 404);
        // Scheme/port come from deployment configuration, never an untrusted Host header.
        $scheme = parse_url(config('app.url'), PHP_URL_SCHEME) ?: 'https';
        $port = parse_url(config('app.url'), PHP_URL_PORT);

        return $scheme.'://'.$domain.($port ? ':'.$port : '');
    }

    public function mediaVisible(int $id): bool
    {
        if (! $this->gate->allows('WEBSITE')) {
            return false;
        }
        if ($this->query->table('website_profiles')->where(fn ($q) => $q->where('logo_id', $id)->orWhere('cover_id', $id))->exists()) {
            return true;
        }
        foreach (ContentCatalog::TYPES as $type => $definition) {
            if ($this->published($type, false)->where('media_id', $id)->exists()) {
                return true;
            }
        }

        return $this->query->table('gallery_items')->where('media_id', $id)
            ->whereIn('gallery_id', $this->published('galeri')->select('galleries.id'))->exists();
    }

    public function image(?int $id): ?string
    {
        if (! $id) {
            return null;
        }
        $file = $this->query->table('media_files')->where('id', $id)->where('visibility', 'PUBLIC')->first();

        return $file && str_starts_with($file->mime, 'image/') && $this->mediaVisible($id) ? '/media/'.$id : null;
    }
}
