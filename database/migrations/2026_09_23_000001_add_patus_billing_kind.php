<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Iuran, patus, dan dedosan adalah tiga hal berbeda (rutin/insidental vs santunan duka vs denda
    // ketidakhadiran). Patus sebelumnya harus dicatat sebagai "Iuran" atau "Lainnya" tanpa pembeda;
    // kini punya kategori sendiri agar tampil dan terlapor terpisah, tanpa mengubah alur penagihan KK
    // yang sudah ada (FinanceManager hanya membedakan DEDOSAN vs selainnya).
    public function up(): void
    {
        DB::statement("ALTER TABLE billing_types MODIFY kind ENUM('IURAN','PATUS','DEDOSAN','OTHER') NOT NULL");
        DB::statement("ALTER TABLE billing_events MODIFY kind ENUM('IURAN','PATUS','DEDOSAN','OTHER') NOT NULL");
        DB::statement("ALTER TABLE bills MODIFY kind ENUM('IURAN','PATUS','DEDOSAN','OTHER') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("UPDATE billing_types SET kind = 'OTHER' WHERE kind = 'PATUS'");
        DB::statement("UPDATE billing_events SET kind = 'OTHER' WHERE kind = 'PATUS'");
        DB::statement("UPDATE bills SET kind = 'OTHER' WHERE kind = 'PATUS'");
        DB::statement("ALTER TABLE billing_types MODIFY kind ENUM('IURAN','DEDOSAN','OTHER') NOT NULL");
        DB::statement("ALTER TABLE billing_events MODIFY kind ENUM('IURAN','DEDOSAN','OTHER') NOT NULL");
        DB::statement("ALTER TABLE bills MODIFY kind ENUM('IURAN','DEDOSAN','OTHER') NOT NULL");
    }
};
