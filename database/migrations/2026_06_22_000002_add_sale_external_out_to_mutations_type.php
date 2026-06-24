<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Tambah nilai 'sale_external_out' (Penjualan Eksternal — barang keluar ke pihak luar)
    // ke ENUM kolom mutations.type. Wajib menyebut SEMUA nilai lama + yang baru.
    public function up(): void
    {
        DB::statement("ALTER TABLE mutations MODIFY COLUMN type ENUM("
            . "'opening_stock','purchase_zhisheng','purchase_supplier',"
            . "'transfer_internal','transfer_external',"
            . "'sale_internal','sale_external','sale_external_out') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE mutations MODIFY COLUMN type ENUM("
            . "'opening_stock','purchase_zhisheng','purchase_supplier',"
            . "'transfer_internal','transfer_external',"
            . "'sale_internal','sale_external') NOT NULL");
    }
};
