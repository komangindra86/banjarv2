<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Lokasi Banjar cukup satu tautan Google Maps (lebih mudah daripada mengisi lintang & bujur).
// Kolom latitude/longitude dibiarkan untuk rollback; koordinat lama dijadikan tautan.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_profiles', fn (Blueprint $t) => $t->string('maps_url', 500)->nullable()->after('email'));
        foreach (DB::table('website_profiles')->whereNotNull('latitude')->whereNotNull('longitude')->get(['id', 'latitude', 'longitude']) as $row) {
            DB::table('website_profiles')->where('id', $row->id)->update(['maps_url' => 'https://www.google.com/maps/search/?api=1&query='.rawurlencode(((float) $row->latitude).','.((float) $row->longitude))]);
        }
    }

    public function down(): void
    {
        Schema::table('website_profiles', fn (Blueprint $t) => $t->dropColumn('maps_url'));
    }
};
