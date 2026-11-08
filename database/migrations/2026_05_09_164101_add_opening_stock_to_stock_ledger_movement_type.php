<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite (database test) tidak mengenal MODIFY — ubah lewat Schema builder,
        // hasilnya sama. Jalur MySQL/MariaDB di bawah tidak berubah.
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('stock_ledger', fn (Blueprint $t) => $t->enum('movement_type', [
                'purchase_in', 'opening_stock', 'transfer_in', 'transfer_out', 'production_in',
                'production_out', 'sale_deduction', 'waste', 'opname_adjustment',
            ])->change());
            return;
        }

        DB::statement("ALTER TABLE stock_ledger MODIFY COLUMN movement_type ENUM(
            'purchase_in',
            'opening_stock',
            'transfer_in',
            'transfer_out',
            'production_in',
            'production_out',
            'sale_deduction',
            'waste',
            'opname_adjustment'
        ) NOT NULL");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('stock_ledger', fn (Blueprint $t) => $t->enum('movement_type', [
                'purchase_in', 'transfer_in', 'transfer_out', 'production_in',
                'production_out', 'sale_deduction', 'waste', 'opname_adjustment',
            ])->change());
            return;
        }

        DB::statement("ALTER TABLE stock_ledger MODIFY COLUMN movement_type ENUM(
            'purchase_in',
            'transfer_in',
            'transfer_out',
            'production_in',
            'production_out',
            'sale_deduction',
            'waste',
            'opname_adjustment'
        ) NOT NULL");
    }
};
