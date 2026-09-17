<?php

use App\Platform\Audit\Audit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banjar_settings', fn (Blueprint $table) => $table->string('accent_color', 7)->default('#b94715')->change());
        // Only replace the previous default; retain deliberate tenant customization.
        DB::transaction(function () {
            foreach (DB::table('banjar_settings')->where('accent_color', '#176b54')->lockForUpdate()->get() as $settings) {
                DB::table('banjar_settings')->where('id', $settings->id)->update(['accent_color' => '#b94715', 'updated_at' => now()]);
                app(Audit::class)->record($settings->banjar_id, null, 'settings.brand_refreshed', 'settings', $settings->id, ['accent_color' => '#176b54'], ['accent_color' => '#b94715']);
            }
        });
    }

    public function down(): void
    {
        // Restores the schema default without overwriting later tenant choices or history.
        Schema::table('banjar_settings', fn (Blueprint $table) => $table->string('accent_color', 7)->default('#176b54')->change());
    }
};
