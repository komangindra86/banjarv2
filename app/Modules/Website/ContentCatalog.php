<?php

namespace App\Modules\Website;

// Nama jenis konten sama di menu pengurus dan website (§12): Pengumuman, Berita, Kegiatan, Dokumen, dst.
// hint = petunjuk untuk pengurus "isi apa di sini"; lead = kalimat pembuka halaman website.
final class ContentCatalog
{
    public const TYPES = [
        'pengumuman' => ['table' => 'announcements', 'label' => 'Pengumuman',
            'hint' => 'Info yang perlu segera diketahui krama, misalnya undangan paruman, berita duka, atau perubahan jadwal. Bisa diberi batas berlaku.',
            'lead' => 'Informasi penting dari pengurus untuk krama Banjar.'],
        'berita' => ['table' => 'news_posts', 'label' => 'Berita',
            'hint' => 'Tulisan yang tetap tersimpan di website, misalnya cerita piodalan, gotong royong, lomba, sejarah, atau tradisi Banjar, beserta fotonya.',
            'lead' => 'Cerita kegiatan dan tulisan tentang Banjar.'],
        'agenda' => ['table' => 'activities', 'label' => 'Kegiatan',
            'hint' => 'Jadwal kegiatan yang akan datang, misalnya paruman, ngayah, atau upacara.',
            'lead' => 'Jadwal paruman, ngayah, dan kegiatan bersama krama Banjar.'],
        'pengurus' => ['table' => 'public_officials', 'label' => 'Pengurus',
            'hint' => 'Nama dan jabatan pengurus Banjar yang tampil di website.',
            'lead' => 'Pengurus Banjar yang dapat dihubungi krama.'],
        'layanan' => ['table' => 'service_definitions', 'label' => 'Layanan',
            'hint' => 'Cara mengurus keperluan di Banjar, misalnya surat keterangan, krama pindah datang, atau sewa bale banjar.',
            'lead' => 'Cara mengurus keperluan krama di Banjar.'],
        'dokumen' => ['table' => 'public_documents', 'label' => 'Dokumen',
            'hint' => 'Awig-awig, pararem, laporan, dan formulir yang dapat dibaca dan diunduh krama.',
            'lead' => 'Awig-awig, pararem, laporan, dan formulir Banjar yang dapat dibaca dan diunduh.'],
        'galeri' => ['table' => 'galleries', 'label' => 'Galeri',
            'hint' => 'Kumpulan foto kegiatan tanpa tulisan panjang. Untuk cerita kegiatan, pakai Berita.',
            'lead' => 'Foto-foto kegiatan Banjar.'],
    ];

    public static function get(string $type): array
    {
        abort_unless(isset(self::TYPES[$type]), 404);

        return self::TYPES[$type] + ['module' => $type === 'agenda' ? 'ACTIVITY' : 'WEBSITE', 'permission' => $type === 'agenda' ? 'activity.manage' : 'website.manage'];
    }
}
