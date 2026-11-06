<?php

namespace App\Modules\Website;

use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Data terstruktur schema.org (JSON-LD) untuk website Banjar, agar Google memahami halaman: Banjar sebagai
 * organisasi, Berita/Pengumuman sebagai artikel, Kegiatan sebagai acara, dan jalur remah roti (breadcrumb).
 */
final class StructuredData
{
    public function __construct(private string $baseUrl, private object $banjar, private ?object $profile, private ?string $logo) {}

    public function organization(): array
    {
        $org = ['@type' => 'Organization', '@id' => $this->baseUrl.'/#organisasi', 'name' => $this->banjar->name, 'url' => $this->baseUrl.'/'];
        if ($this->logo) {
            $org['logo'] = $this->absolute($this->logo);
        }
        if ($this->profile?->description) {
            $org['description'] = $this->text($this->profile->description, 300);
        }
        if ($this->profile?->address) {
            $org['address'] = $this->address();
        }
        foreach (['phone' => 'telephone', 'email' => 'email'] as $column => $key) {
            if ($this->profile?->$column) {
                $org[$key] = $this->profile->$column;
            }
        }

        return $org;
    }

    /** Beranda dan Profil: Banjar sebagai organisasi dan website-nya. */
    public function site(): string
    {
        return $this->encode([
            $this->organization(),
            ['@type' => 'WebSite', '@id' => $this->baseUrl.'/#website', 'name' => $this->banjar->name, 'url' => $this->baseUrl.'/', 'inLanguage' => 'id-ID', 'publisher' => ['@id' => $this->baseUrl.'/#organisasi']],
        ]);
    }

    /** Halaman daftar (Berita, Pengumuman, ...): jalur Beranda › Jenis. */
    public function listing(string $type, string $label): string
    {
        return $this->encode([$this->breadcrumb([[$label, $this->baseUrl.'/'.$type]])]);
    }

    /** Halaman isi: jalur remah roti, ditambah Article (Berita, Pengumuman) atau Event (Kegiatan). */
    public function item(string $type, string $label, object $item, string $url, array $images): string
    {
        $graph = [$this->breadcrumb([[$label, $this->baseUrl.'/'.$type], [$item->title, $url]])];
        $images = array_values(array_map(fn ($image) => $this->absolute($image), $images));
        $publisher = ['@id' => $this->baseUrl.'/#organisasi'];
        if (in_array($type, ['berita', 'pengumuman'], true)) {
            $article = ['@type' => $type === 'berita' ? 'NewsArticle' : 'Article', 'headline' => Str::limit($item->title, 105, '…'), 'mainEntityOfPage' => $url, 'url' => $url,
                'datePublished' => $this->date($item->published_at), 'dateModified' => $this->date($item->updated_at ?? $item->published_at),
                'author' => $publisher, 'publisher' => $publisher, 'inLanguage' => 'id-ID'];
            if ($images) {
                $article['image'] = $images;
            }
            if ($item->body) {
                $article['description'] = $this->text($item->body, 300);
            }
            $graph[] = $article;
            $graph[] = $this->organization();
        } elseif ($type === 'agenda') {
            $event = ['@type' => 'Event', 'name' => $item->title, 'url' => $url, 'startDate' => $this->date($item->starts_at),
                'eventStatus' => 'https://schema.org/EventScheduled', 'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
                'location' => ['@type' => 'Place', 'name' => $item->location ?: $this->banjar->name, 'address' => $this->profile?->address ? $this->address() : $this->banjar->name.', Bali'],
                'organizer' => ['@type' => 'Organization', 'name' => $this->banjar->name, 'url' => $this->baseUrl.'/'], 'inLanguage' => 'id-ID'];
            if ($item->ends_at) {
                $event['endDate'] = $this->date($item->ends_at);
            }
            if ($images) {
                $event['image'] = $images;
            }
            if ($item->body) {
                $event['description'] = $this->text($item->body, 300);
            }
            $graph[] = $event;
        }

        return $this->encode($graph);
    }

    private function breadcrumb(array $trail): array
    {
        $items = [['Beranda', $this->baseUrl.'/'], ...$trail];

        return ['@type' => 'BreadcrumbList', 'itemListElement' => array_map(fn ($entry, $i) => ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $entry[0], 'item' => $entry[1]], $items, array_keys($items))];
    }

    private function address(): array
    {
        return ['@type' => 'PostalAddress', 'streetAddress' => $this->text($this->profile->address, 200), 'addressRegion' => 'Bali', 'addressCountry' => 'ID'];
    }

    private function absolute(string $path): string
    {
        return str_starts_with($path, 'http') ? $path : $this->baseUrl.$path;
    }

    private function date(mixed $value): string
    {
        return Carbon::parse($value)->timezone('Asia/Makassar')->toIso8601String();
    }

    private function text(string $value, int $limit): string
    {
        return Str::limit(trim(preg_replace('/\s+/u', ' ', $value)), $limit);
    }

    // JSON_HEX_TAG mencegah isi tulisan pengurus menutup tag <script> lebih awal.
    private function encode(array $graph): string
    {
        return json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
    }
}
