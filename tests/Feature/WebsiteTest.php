<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Website\ContentCatalog;
use App\Modules\Website\ContentManager;
use App\Modules\Website\MediaManager;
use App\Platform\Tenancy\CurrentBanjar;
use App\Platform\Tenants\TenantManager;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WebsiteTest extends TestCase
{
    use RefreshDatabase;

    private int $a;

    private int $b;

    private User $admin;

    private string $host = 'http://alpha.localhost';

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || DB::connection()->getDatabaseName() !== 'banjarv2_testing') {
            throw new \RuntimeException('Refusing to refresh anything except banjarv2_testing on MySQL.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['platform.host' => 'admin.banjar.localhost', 'platform.tenant_base_domain' => 'localhost', 'app.url' => 'http://admin.banjar.localhost:18915']);
        $this->seed(RoleSeeder::class);
        $super = User::factory()->create(['status' => 'ACTIVE']);
        DB::table('platform_role_assignments')->insert(['user_id' => $super->id, 'role_id' => DB::table('roles')->where('code', 'SUPER_ADMIN')->value('id')]);
        foreach (['a' => 'alpha', 'b' => 'beta'] as $key => $slug) {
            $this->$key = app(TenantManager::class)->create($super, ['name' => 'Banjar '.$slug, 'slug' => $slug, 'admin_name' => 'Admin '.$slug, 'admin_email' => $slug.'@example.test', 'admin_password' => 'Test-Password-12345', 'admin_password_confirmation' => 'Test-Password-12345']);
        }
        $this->admin = User::where('email', 'alpha@example.test')->firstOrFail();
        Storage::fake('local');
    }

    private function login(): void
    {
        $this->post($this->host.'/login', ['email' => $this->admin->email, 'password' => 'Test-Password-12345'])->assertRedirect('/admin');
    }

    private function content(string $type = 'pengumuman', array $data = [], ?int $tenant = null): int
    {
        $extra = match ($type) {
            'agenda' => ['starts_at' => now()->addDay()],
            'pengurus' => ['position' => 'Penyarikan'],
            default => [],
        };

        return DB::table(ContentCatalog::get($type)['table'])->insertGetId($data + $extra + ['banjar_id' => $tenant ?? $this->a, 'title' => 'Informasi '.$type, 'slug' => 'informasi-'.$type, 'body' => 'Isi informasi resmi', 'status' => 'PUBLISHED', 'published_at' => now()->subHour(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function file(string $visibility = 'PUBLIC', ?int $tenant = null, string $mime = 'image/webp'): int
    {
        $path = 'banjars/'.($tenant ?? $this->a).'/media/'.uniqid().($mime === 'application/pdf' ? '.pdf' : '.webp');
        Storage::disk('local')->put($path, $mime === 'application/pdf' ? '%PDF-1.4 test' : 'test image');

        return DB::table('media_files')->insertGetId(['banjar_id' => $tenant ?? $this->a, 'path' => $path, 'name' => 'Media uji', 'mime' => $mime, 'size' => 10, 'visibility' => $visibility, 'uploader_id' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_photo_forms_pick_files_directly_without_dialog_or_visibility_choice(): void
    {
        $this->login();
        foreach (['/admin/profile', '/admin/content/pengumuman/create', '/admin/content/galeri/create', '/admin/content/dokumen/create', '/admin/media'] as $path) {
            $html = $this->get($this->host.$path)->assertOk()->getContent();
            $this->assertStringContainsString('enctype="multipart/form-data"', $html, $path);
            $this->assertStringContainsString('data-photo-picker', $html, $path);
            $this->assertStringNotContainsString('<dialog', $html, $path);
            $this->assertStringNotContainsString('Privat', $html, $path);
        }
        $this->get($this->host.'/admin/content/pengumuman/create')->assertSee('name="photos[]"', false)->assertSee(' multiple ', false)->assertSee('Foto pertama menjadi foto utama');
        $this->get($this->host.'/admin/profile')->assertSee('name="logo"', false)->assertSee('name="cover"', false);
    }

    public function test_content_photos_upload_together_preview_reorder_and_return_to_list(): void
    {
        $this->login();
        $photos = fn (...$names) => array_map(fn ($name) => UploadedFile::fake()->image($name, 640, 480), $names);
        $this->post($this->host.'/admin/content/pengumuman', ['title' => 'Ngaben massal', 'status' => 'PUBLISHED', 'priority' => 'NORMAL', 'photos' => $photos('satu.jpg', 'dua.jpg', 'tiga.jpg')])
            ->assertRedirect('/admin/content/pengumuman')->assertSessionHas('success');
        $item = DB::table('announcements')->where('title', 'Ngaben massal')->first();
        $ids = DB::table('content_photos')->where('content_type', 'pengumuman')->where('content_id', $item->id)->orderBy('sort_order')->pluck('media_id')->all();
        $this->assertCount(3, $ids);
        $this->assertSame($ids[0], (int) $item->media_id);
        $this->assertSame(['satu.jpg', 'dua.jpg', 'tiga.jpg'], DB::table('media_files')->whereIn('id', $ids)->orderBy('id')->pluck('name')->all());
        $this->assertSame(['PUBLIC'], DB::table('media_files')->whereIn('id', $ids)->distinct()->pluck('visibility')->all());
        // Website: foto utama besar, foto lain di bawah isi; semuanya bisa dibuka pengunjung.
        $page = $this->get($this->host.'/pengumuman/ngaben-massal')->assertOk();
        foreach ($ids as $id) {
            $page->assertSee('/media/'.$id, false);
            $this->get($this->host.'/media/'.$id)->assertOk();
        }
        // Edit: foto lama tampil sebagai pratinjau; hapus foto pertama, tambah satu foto baru di akhir.
        $this->get($this->host.'/admin/content/pengumuman/'.$item->id.'/edit')->assertOk()->assertSee('name="keep_photos[]" value="'.$ids[0].'"', false)->assertSee('/admin/media/'.$ids[2].'/download?lihat=1', false);
        $this->get($this->host.'/admin/media/'.$ids[2].'/download?lihat=1')->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->patch($this->host.'/admin/content/pengumuman/'.$item->id, ['title' => 'Ngaben massal', 'status' => 'PUBLISHED', 'priority' => 'NORMAL', 'keep_photos' => [$ids[1], $ids[2]], 'photos' => $photos('empat.jpg')])
            ->assertRedirect('/admin/content/pengumuman');
        $after = DB::table('content_photos')->where('content_id', $item->id)->orderBy('sort_order')->pluck('media_id')->all();
        $this->assertSame([$ids[1], $ids[2]], array_slice($after, 0, 2));
        $this->assertCount(3, $after);
        $this->assertSame($ids[1], (int) DB::table('announcements')->where('id', $item->id)->value('media_id'));
        $this->get($this->host.'/media/'.$ids[0])->assertNotFound();
        // Semua foto dilepas → tanpa foto utama.
        $this->patch($this->host.'/admin/content/pengumuman/'.$item->id, ['title' => 'Ngaben massal', 'status' => 'PUBLISHED', 'priority' => 'NORMAL'])->assertSessionHasNoErrors();
        $this->assertNull(DB::table('announcements')->where('id', $item->id)->value('media_id'));
        $this->assertSame(0, DB::table('content_photos')->where('content_id', $item->id)->count());
    }

    public function test_photos_survive_validation_error_and_reject_wrong_files(): void
    {
        $this->login();
        $this->from($this->host.'/admin/content/berita/create')->post($this->host.'/admin/content/berita', ['title' => '', 'status' => 'DRAFT', 'photos' => [UploadedFile::fake()->image('kegiatan.jpg', 300, 200)]])
            ->assertSessionHasErrors('title');
        $saved = DB::table('media_files')->where('name', 'kegiatan.jpg')->value('id');
        $this->assertNotNull($saved);
        // Foto yang sudah terunggah tetap tampil di formulir, tidak perlu dipilih ulang.
        $this->get($this->host.'/admin/content/berita/create')->assertOk()->assertSee('name="keep_photos[]" value="'.$saved.'"', false);
        $this->post($this->host.'/admin/content/berita', ['title' => 'Salah berkas', 'status' => 'DRAFT', 'photos' => [UploadedFile::fake()->create('surat.pdf', 10, 'application/pdf')]])->assertSessionHasErrors('photos.0');
        $this->post($this->host.'/admin/content/berita', ['title' => 'Terlalu banyak', 'status' => 'DRAFT', 'photos' => array_map(fn ($i) => UploadedFile::fake()->image('f'.$i.'.jpg', 10, 10), range(1, 21))])->assertSessionHasErrors('photos');
        $this->assertDatabaseMissing('news_posts', ['title' => 'Salah berkas']);
    }

    public function test_public_pages_show_only_current_tenant_and_escape_content(): void
    {
        $this->content('pengumuman', ['title' => 'Kabar Alpha', 'body' => '<script>alert(1)</script>']);
        $this->content('pengumuman', ['title' => 'Rahasia Beta'], $this->b);
        $this->get($this->host.'/')->assertOk()->assertSee('Kabar Alpha')->assertDontSee('Rahasia Beta');
        $this->get($this->host.'/pengumuman/informasi-pengumuman')->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)->assertSee('https://wa.me/', false);
        $this->get('http://beta.localhost/pengumuman/informasi-pengumuman')->assertOk()->assertSee('Rahasia Beta')->assertDontSee('Kabar Alpha');
        $this->get('http://unknown.localhost/')->assertNotFound();
        $this->get('http://admin.banjar.localhost/profil')->assertNotFound();
        $this->get('http://admin.banjar.localhost/pengumuman')->assertNotFound();
    }

    public function test_drafts_future_archived_and_expired_have_correct_visibility(): void
    {
        foreach (['draft' => ['status' => 'DRAFT'], 'future' => ['published_at' => now()->addHour()], 'archived' => ['status' => 'ARCHIVED'], 'expired' => ['expires_at' => now()->subMinute()]] as $slug => $data) {
            $this->content('pengumuman', $data + ['title' => 'Judul '.$slug, 'slug' => $slug]);
            $this->get($this->host.'/')->assertDontSee('Judul '.$slug);
            $this->get($this->host.'/sitemap.xml')->assertDontSee('/pengumuman/'.$slug, false);
        }
        foreach (['draft', 'future', 'archived'] as $slug) {
            $this->get($this->host.'/pengumuman/'.$slug)->assertNotFound();
        }
        $this->get($this->host.'/pengumuman/expired')->assertOk()->assertSee('Masa berlaku pengumuman ini telah berakhir');
        $this->get($this->host.'/pengumuman?arsip=1')->assertOk()->assertSee('Judul expired')->assertDontSee('Judul draft');
    }

    public function test_all_content_types_can_be_created_edited_published_and_archived(): void
    {
        $this->login();
        foreach (ContentCatalog::TYPES as $type => $definition) {
            $this->get($this->host.'/admin/content/'.$type.'/create')->assertOk();
            $data = ['title' => 'Baru '.$type, 'status' => 'DRAFT', 'body' => 'Isi '.$type, 'priority' => 'IMPORTANT', 'position' => 'Kelian', 'sort_order' => 1, 'starts_at' => now()->addDay()->timezone('Asia/Makassar')->format('Y-m-d\TH:i')];
            $this->post($this->host.'/admin/content/'.$type, $data)->assertRedirect()->assertSessionHasNoErrors();
            $id = DB::table($definition['table'])->where('banjar_id', $this->a)->value('id');
            $this->get($this->host.'/admin/content/'.$type.'/'.$id.'/edit')->assertOk()->assertSee('Baru '.$type);
            $this->get($this->host.'/'.$type.'/baru-'.$type)->assertNotFound();
            $data['status'] = 'PUBLISHED';
            $this->patch($this->host.'/admin/content/'.$type.'/'.$id, $data)->assertRedirect()->assertSessionHasNoErrors();
            $this->get($this->host.'/'.$type.'/baru-'.$type)->assertOk()->assertSee('Baru '.$type);
            $this->get($this->host.'/'.$type)->assertOk();
            $this->delete($this->host.'/admin/content/'.$type.'/'.$id)->assertRedirect();
            $this->get($this->host.'/'.$type.'/baru-'.$type)->assertNotFound();
            $this->assertDatabaseHas('audit_logs', ['entity_type' => $definition['table'], 'entity_id' => (string) $id, 'action' => 'content.archived']);
        }
    }

    public function test_content_idor_and_injected_tenant_ids_are_rejected_or_ignored(): void
    {
        $foreign = $this->content('berita', [], $this->b);
        $this->login();
        $this->get($this->host.'/admin/content/berita/'.$foreign.'/edit')->assertNotFound();
        $this->patch($this->host.'/admin/content/berita/'.$foreign, ['title' => 'Overwrite', 'status' => 'PUBLISHED'])->assertNotFound();
        $this->delete($this->host.'/admin/content/berita/'.$foreign)->assertNotFound();
        $this->post($this->host.'/admin/content/berita', ['title' => 'Milik alpha', 'status' => 'DRAFT', 'banjar_id' => $this->b])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('news_posts', ['title' => 'Milik alpha', 'banjar_id' => $this->a]);
        $this->get($this->host.'/admin/content/berita?banjar_id='.$this->b)->assertDontSee('Informasi berita');
    }

    public function test_media_requires_public_visibility_and_published_reference(): void
    {
        $private = $this->file('PRIVATE');
        $orphan = $this->file();
        $draft = $this->file();
        $foreign = $this->file('PUBLIC', $this->b);
        $live = $this->file();
        $this->content('berita', ['media_id' => $private]);
        $this->content('berita', ['slug' => 'draft', 'status' => 'DRAFT', 'media_id' => $draft]);
        $liveId = $this->content('berita', ['slug' => 'live', 'media_id' => $live]);
        foreach ([$private, $orphan, $draft, $foreign] as $id) {
            $this->get($this->host.'/media/'.$id)->assertNotFound();
        }
        $this->get($this->host.'/media/'.$live)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        DB::table('news_posts')->where('id', $liveId)->update(['status' => 'ARCHIVED']);
        $this->get($this->host.'/media/'.$live)->assertNotFound();
        $this->login();
        $this->get($this->host.'/admin/media/'.$private.'/download')->assertOk();
        $this->get($this->host.'/admin/media/'.$foreign.'/download')->assertNotFound();
        // Foto Privat lama yang dipasang lewat formulir otomatis menjadi Publik.
        $this->post($this->host.'/admin/content/berita', ['title' => 'Pasang foto lama', 'status' => 'PUBLISHED', 'keep_photos' => [$private]])->assertSessionHasNoErrors();
        $this->get($this->host.'/media/'.$private)->assertOk();
    }

    public function test_upload_reencodes_images_and_rejects_executable_svg_and_oversized_files(): void
    {
        $this->login();
        $this->post($this->host.'/admin/media', ['files' => [UploadedFile::fake()->image('photo.png', 120, 120), UploadedFile::fake()->image('kamera.jpg', 4000, 3000)]])->assertRedirect()->assertSessionHasNoErrors();
        $file = DB::table('media_files')->orderBy('id')->first();
        $this->assertSame(['image/webp', 'PUBLIC'], [$file->mime, $file->visibility]);
        $this->assertStringStartsWith('banjars/'.$this->a.'/media/', $file->path);
        $this->assertStringStartsWith('RIFF', Storage::disk('local')->get($file->path));
        // Foto kamera diperkecil ke sisi terpanjang 2000 px.
        $this->assertSame([2000, 1500], array_slice(getimagesizefromstring(Storage::disk('local')->get(DB::table('media_files')->where('name', 'kamera.jpg')->value('path'))), 0, 2));
        foreach ([UploadedFile::fake()->createWithContent('evil.php', '<?php echo 1;'), UploadedFile::fake()->createWithContent('evil.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf')] as $upload) {
            $this->post($this->host.'/admin/media', ['files' => [$upload]])->assertSessionHasErrors('files.0');
        }
        $this->post($this->host.'/admin/media', [])->assertSessionHasErrors('files');
        $this->assertDatabaseCount('media_files', 2);
    }

    public function test_gallery_profile_and_document_visibility(): void
    {
        $photo = $this->file();
        $private = $this->file('PRIVATE');
        $pdf = $this->file('PUBLIC', null, 'application/pdf');
        $this->login();
        $this->post($this->host.'/admin/content/galeri', ['title' => 'Foto Banjar', 'status' => 'PUBLISHED', 'keep_photos' => [$photo]])->assertRedirect()->assertSessionHasNoErrors();
        $this->get($this->host.'/galeri/foto-banjar')->assertOk()->assertSee('/media/'.$photo, false)->assertDontSee('/media/'.$private, false);
        $this->assertSame([$photo], DB::table('gallery_items')->pluck('media_id')->map(fn ($id) => (int) $id)->all());
        $this->post($this->host.'/admin/content/dokumen', ['title' => 'Panduan', 'status' => 'PUBLISHED', 'keep_document' => [$pdf]])->assertRedirect()->assertSessionHasNoErrors();
        $this->get($this->host.'/dokumen/panduan')->assertOk()->assertSee('Unduh dokumen');
        $this->get($this->host.'/media/'.$pdf)->assertOk()->assertHeader('Content-Disposition', 'attachment; filename=berkas-'.$pdf.'.pdf');
        $this->patch($this->host.'/admin/profile', ['description' => 'Cerita alpha', 'keep_logo' => $photo, 'maps_url' => 'https://maps.app.goo.gl/AbCd1234'])->assertRedirect('/admin/website')->assertSessionHasNoErrors();
        $this->get($this->host.'/profil')->assertOk()->assertSee('Cerita alpha')->assertSee('href="https://maps.app.goo.gl/AbCd1234"', false);
        $this->get('http://beta.localhost/profil')->assertOk()->assertDontSee('Cerita alpha');
        // Hanya tautan Google Maps https yang diterima.
        foreach (['http://maps.app.goo.gl/x', 'https://evil.example/maps', 'javascript:alert(1)', 'https://google.com.evil.io/maps'] as $bad) {
            $this->patch($this->host.'/admin/profile', ['maps_url' => $bad])->assertSessionHasErrors('maps_url');
        }
        foreach (['https://www.google.com/maps/place/Bale+Banjar/@-8.6,115.2,17z', 'https://www.google.co.id/maps/@-8.6,115.2,17z', 'https://goo.gl/maps/xyz', 'https://maps.google.com/?q=-8.6,115.2'] as $good) {
            $this->patch($this->host.'/admin/profile', ['maps_url' => $good])->assertSessionHasNoErrors();
        }
        // Logo menggantikan tanda "B" di website publik, halaman pengurus, dan ikon tab.
        $this->get($this->host.'/')->assertOk()->assertSee('<link rel="icon" href="/media/'.$photo.'"', false)->assertSee('class="public-logo"', false);
        $this->get($this->host.'/admin/website')->assertOk()->assertSee('class="brand-logo" src="/media/'.$photo.'"', false)->assertDontSee('<span class="brand-mark"', false);
        // Logo dihapus dari formulir → kembali ke logo Bali Santih; logo baru bisa dipilih langsung.
        $this->patch($this->host.'/admin/profile', ['keep_logo' => ''])->assertSessionHasNoErrors();
        $this->get($this->host.'/admin/website')->assertOk()->assertSee('class="brand-logo" src="/images/balisantih-logo.png"', false);
        $this->get($this->host.'/')->assertOk()->assertSee('src="/images/balisantih-logo.png" alt="" class="public-logo"', false)->assertSee('/images/balisantih-share.jpg', false)->assertDontSee('site-mark', false);
        $this->patch($this->host.'/admin/profile', ['keep_logo' => '', 'logo' => UploadedFile::fake()->image('logo-baru.png', 200, 200)])->assertSessionHasNoErrors();
        $logo = DB::table('media_files')->where('name', 'logo-baru.png')->first();
        $this->assertSame(['PUBLIC', $logo->id], [$logo->visibility, (int) DB::table('website_profiles')->where('banjar_id', $this->a)->value('logo_id')]);
        $this->get($this->host.'/admin/profile')->assertOk()->assertSee('name="keep_logo" value="'.$logo->id.'"', false);
    }

    public function test_logo_becomes_share_preview_and_app_icons(): void
    {
        // Logo asli (WebP transparan, tidak persegi) → JPEG 1200×630 untuk WhatsApp dan PNG persegi untuk ikon aplikasi.
        $image = imagecreatetruecolor(300, 200);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledellipse($image, 150, 100, 180, 180, imagecolorallocate($image, 185, 71, 21));
        ob_start();
        imagewebp($image);
        $path = 'banjars/'.$this->a.'/media/logo.webp';
        Storage::disk('local')->put($path, ob_get_clean());
        $logo = DB::table('media_files')->insertGetId(['banjar_id' => $this->a, 'path' => $path, 'name' => 'Logo', 'mime' => 'image/webp', 'size' => 10, 'visibility' => 'PUBLIC', 'uploader_id' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->login();
        $this->patch($this->host.'/admin/profile', ['keep_logo' => $logo])->assertSessionHasNoErrors();

        $brand = '/brand/'.$this->a.'/';
        $this->get($this->host.'/berita')->assertOk()->assertSee($brand.'share.jpg?v='.$logo, false)->assertSee('<meta property="og:image:width" content="1200">', false)
            ->assertSee('<link rel="apple-touch-icon" href="'.$brand.'apple-180.png?v='.$logo.'"', false);
        $this->get($this->host.'/admin')->assertOk()->assertSee('<link rel="icon" href="'.$brand.'icon-192.png?v='.$logo.'"', false);
        $this->assertSame([$brand.'icon-192.png?v='.$logo, $brand.'icon-512.png?v='.$logo, $brand.'maskable-512.png?v='.$logo], array_column($this->get($this->host.'/manifest.webmanifest')->json('icons'), 'src'));

        foreach (['share.jpg' => [1200, 630, 'image/jpeg'], 'icon-512.png' => [512, 512, 'image/png'], 'maskable-512.png' => [512, 512, 'image/png'], 'apple-180.png' => [180, 180, 'image/png']] as $file => [$w, $h, $mime]) {
            $response = $this->get($this->host.$brand.$file)->assertOk()->assertHeader('Content-Type', $mime);
            $size = getimagesize($response->baseResponse->getFile()->getPathname());
            $this->assertSame([$w, $h, $mime], [$size[0], $size[1], $size['mime']]);
        }
        $this->get($this->host.$brand.'share.png')->assertNotFound();
        $this->get($this->host.$brand.'lain-1.png')->assertNotFound();
        $this->get('http://beta.localhost'.$brand.'icon-192.png')->assertNotFound();
        // Kartu Banjar di dasbor Super Admin memakai ikon yang sama.
        $this->get('http://admin.banjar.localhost'.$brand.'icon-192.png')->assertOk()->assertHeader('Content-Type', 'image/png');

        // Logo dijadikan privat → kembali ke ikon bawaan.
        DB::table('media_files')->where('id', $logo)->update(['visibility' => 'PRIVATE']);
        $this->get($this->host.$brand.'icon-192.png')->assertNotFound();
        $this->get($this->host.'/manifest.webmanifest')->assertJsonPath('icons.0.src', '/icons/icon-192.png');
    }

    public function test_five_balinese_templates_and_profile_sentence_templates(): void
    {
        $this->login();
        $settings = DB::table('banjar_settings')->where('banjar_id', $this->a)->value('id');
        $page = $this->get($this->host.'/admin/settings')->assertOk();
        foreach (['Bali Santih', 'Subak', 'Poleng', 'Prada', 'Segara'] as $name) {
            $page->assertSee($name);
        }
        $this->patch($this->host.'/admin/settings/'.$settings, ['template_code' => 'template-04', 'accent_color' => '#8c1c22'])->assertSessionHasNoErrors();
        $this->get($this->host.'/')->assertOk()->assertSee('class="public-site template-04"', false);
        $this->patch($this->host.'/admin/settings/'.$settings, ['template_code' => 'template-99', 'accent_color' => '#8c1c22'])->assertSessionHasErrors('template_code');

        $this->get($this->host.'/admin/profile')->assertOk()->assertSee('Pakai contoh kalimat')->assertSee('data-fill-template="#field-history"', false)
            ->assertSee('Tri Hita Karana')->assertSee('Lokasi di Google Maps')->assertDontSee('Lintang lokasi');
    }

    public function test_foreign_media_activity_and_gallery_references_are_invalid(): void
    {
        $foreign = $this->file('PUBLIC', $this->b);
        $activity = $this->content('agenda', [], $this->b);
        $this->login();
        $this->post($this->host.'/admin/content/berita', ['title' => 'Invalid', 'status' => 'DRAFT', 'keep_photos' => [$foreign]])->assertSessionHasErrors('photos.0');
        $this->post($this->host.'/admin/content/pengumuman', ['title' => 'Invalid', 'status' => 'DRAFT', 'priority' => 'NORMAL', 'related_activity_id' => $activity])->assertSessionHasErrors('related_activity_id');
        $this->post($this->host.'/admin/content/galeri', ['title' => 'Invalid', 'status' => 'DRAFT', 'keep_photos' => [$foreign]])->assertSessionHasErrors('photos.0');
        $this->patch($this->host.'/admin/profile', ['keep_logo' => $foreign])->assertSessionHasErrors('logo_id');
        $this->expectException(QueryException::class);
        $this->content('berita', ['media_id' => $foreign]);
    }

    public function test_modules_gate_routes_services_and_public_activity(): void
    {
        $this->content('agenda');
        $this->get($this->host.'/agenda/informasi-agenda')->assertOk();
        $this->login();
        DB::table('banjar_modules')->where('banjar_id', $this->a)->where('code', 'ACTIVITY')->update(['status' => 'INACTIVE']);
        $this->get($this->host.'/agenda')->assertNotFound();
        $this->get($this->host.'/agenda/informasi-agenda')->assertNotFound();
        $this->get($this->host.'/')->assertDontSee('href="/agenda"', false);
        $this->post($this->host.'/admin/content/agenda', [])->assertForbidden();
        DB::table('banjar_modules')->where('banjar_id', $this->a)->where('code', 'WEBSITE')->update(['status' => 'INACTIVE']);
        foreach (['/', '/profil', '/sitemap.xml'] as $path) {
            $this->get($this->host.$path)->assertNotFound();
        }
        foreach (['/admin/profile', '/admin/media', '/admin/qr', '/admin/content/berita'] as $path) {
            $this->get($this->host.$path)->assertForbidden();
        }
        app(CurrentBanjar::class)->set(DB::table('banjars')->where('id', $this->a)->first());
        try {
            app(ContentManager::class)->save($this->admin, 'berita', null, ['title' => 'Blocked', 'status' => 'DRAFT']);
            $this->fail('Service bypassed module gate');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_kelian_cannot_mutate_website_or_media(): void
    {
        $membership = DB::table('banjar_user_memberships')->where('user_id', $this->admin->id)->value('id');
        DB::table('tenant_role_assignments')->where('membership_id', $membership)->update(['role_id' => DB::table('roles')->where('code', 'KELIAN')->value('id')]);
        $this->login();
        $this->post($this->host.'/admin/content/berita', ['title' => 'Invalid', 'status' => 'PUBLISHED'])->assertForbidden();
        $this->post($this->host.'/admin/media', [])->assertForbidden();
        $this->patch($this->host.'/admin/profile', [])->assertForbidden();
        app(CurrentBanjar::class)->set(DB::table('banjars')->where('id', $this->a)->first());
        $this->expectException(HttpException::class);
        app(MediaManager::class)->upload($this->admin, []);
    }

    public function test_verified_primary_domain_is_used_for_canonical_share_qr_and_sitemap(): void
    {
        $this->content('berita');
        DB::table('tenant_domains')->insert(['banjar_id' => $this->a, 'domain' => 'alias.example.test', 'verification_status' => 'VERIFIED', 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->withHeader('X-Forwarded-Host', 'evil.test')->get('http://alias.example.test/berita/informasi-berita')->assertOk()->assertSee('http://alpha.localhost:18915/berita/informasi-berita', false)->assertDontSee('evil.test')->assertDontSee('alias.example.test');
        $this->get($this->host.'/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->assertSee('http://alpha.localhost:18915/berita/informasi-berita', false)->assertDontSee('/admin/');
        $this->login();
        $this->get($this->host.'/admin/qr')->assertOk()->assertSee('data-url="http://alpha.localhost:18915"', false);
    }

    public function test_automatic_page_address_and_wita_scheduling_validation(): void
    {
        $this->content('berita');
        $this->login();
        $this->post($this->host.'/admin/content/berita', ['title' => ['invalid'], 'status' => 'DRAFT'])->assertSessionHasErrors('title');
        // Alamat halaman otomatis dari judul: tanpa kolom isian, judul kembar diberi nomor, tidak berubah saat judul diedit.
        $this->get($this->host.'/admin/content/berita/create')->assertOk()->assertDontSee('Alamat singkat')->assertDontSee('name="slug"', false);
        $this->post($this->host.'/admin/content/berita', ['title' => 'Informasi Berita', 'slug' => 'abaikan-saya', 'status' => 'PUBLISHED'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post($this->host.'/admin/content/berita', ['title' => 'Informasi Berita', 'status' => 'PUBLISHED'])->assertSessionHasNoErrors();
        $this->post($this->host.'/admin/content/berita', ['title' => 'ᬲᬗ᭄ᬓᭂᬧ᭄', 'status' => 'PUBLISHED'])->assertSessionHasNoErrors();
        $this->assertSame(['informasi-berita', 'informasi-berita-2', 'informasi-berita-3', 'berita'], DB::table('news_posts')->where('banjar_id', $this->a)->orderBy('id')->pluck('slug')->all());
        $second = DB::table('news_posts')->where('slug', 'informasi-berita-2')->value('id');
        $this->patch($this->host.'/admin/content/berita/'.$second, ['title' => 'Judul Diganti', 'slug' => 'baru', 'status' => 'PUBLISHED'])->assertSessionHasNoErrors();
        $this->assertSame('informasi-berita-2', DB::table('news_posts')->where('id', $second)->value('slug'));
        $this->get($this->host.'/berita/informasi-berita-2')->assertOk()->assertSee('Judul Diganti');
        $this->post($this->host.'/admin/content/agenda', ['title' => 'Invalid times', 'status' => 'DRAFT', 'starts_at' => '2026-10-01T19:00', 'ends_at' => '2026-10-01T18:00'])->assertSessionHasErrors('ends_at');
        $this->post($this->host.'/admin/content/pengumuman', ['title' => 'Invalid expiry', 'status' => 'PUBLISHED', 'priority' => 'NORMAL', 'published_at' => '2026-10-01T19:00', 'expires_at' => '2026-10-01T18:00'])->assertSessionHasErrors('expires_at');
        $this->post($this->host.'/admin/content/agenda', ['title' => 'Paruman', 'status' => 'PUBLISHED', 'starts_at' => '2026-10-01T19:00', 'published_at' => '2026-09-15T08:00'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('activities', ['title' => 'Paruman', 'starts_at' => '2026-10-01 11:00:00', 'published_at' => '2026-09-15 00:00:00']);
        $this->get($this->host.'/agenda/paruman')->assertOk()->assertSee('19:00')->assertSee('WITA');
    }
}
