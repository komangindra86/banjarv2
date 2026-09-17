<?php

namespace App\Modules\Website;

final class ContentCatalog
{
    public const TYPES = [
        'pengumuman' => ['table' => 'announcements', 'label' => 'Pengumuman'],
        'berita' => ['table' => 'news_posts', 'label' => 'Berita'],
        'agenda' => ['table' => 'activities', 'label' => 'Agenda'],
        'pengurus' => ['table' => 'public_officials', 'label' => 'Pengurus'],
        'layanan' => ['table' => 'service_definitions', 'label' => 'Layanan'],
        'dokumen' => ['table' => 'public_documents', 'label' => 'Dokumen publik'],
        'galeri' => ['table' => 'galleries', 'label' => 'Galeri'],
    ];

    public static function get(string $type): array
    {
        abort_unless(isset(self::TYPES[$type]), 404);

        return self::TYPES[$type] + ['module' => $type === 'agenda' ? 'ACTIVITY' : 'WEBSITE', 'permission' => $type === 'agenda' ? 'activity.manage' : 'website.manage'];
    }
}
