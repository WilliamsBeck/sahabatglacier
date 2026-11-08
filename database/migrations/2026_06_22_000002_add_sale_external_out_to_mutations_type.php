<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TIPE_LAMA = ['opening_stock', 'purchase_zhisheng', 'purchase_supplier',
        'transfer_internal', 'transfer_external', 'sale_internal', 'sale_external'];

    // Tambah nilai 'sale_external_out' (Penjualan Eksternal — barang keluar ke pihak luar)
    // ke ENUM kolom mutations.type. Wajib menyebut SEMUA nilai lama + yang baru.
    public function up(): void
    {
        // SQLite (database test) tidak mengenal MODIFY — pakai Schema builder, hasilnya
        // sama. Jalur MySQL/MariaDB di bawah tidak berubah.
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('mutations', fn (Blueprint $t) =>
                $t->enum('type', [...self::TIPE_LAMA, 'sale_external_out'])->change());
            return;
        }

        DB::statement("ALTER TABLE mutations MODIFY COLUMN type ENUM("
            . "'opening_stock','purchase_zhisheng','purchase_supplier',"
            . "'transfer_internal','transfer_external',"
            . "'sale_internal','sale_external','sale_external_out') NOT NULL");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('mutations', fn (Blueprint $t) => $t->enum('type', self::TIPE_LAMA)->change());
            return;
        }

        DB::statement("ALTER TABLE mutations MODIFY COLUMN type ENUM("
            . "'opening_stock','purchase_zhisheng','purchase_supplier',"
            . "'transfer_internal','transfer_external',"
            . "'sale_internal','sale_external') NOT NULL");
    }
};
