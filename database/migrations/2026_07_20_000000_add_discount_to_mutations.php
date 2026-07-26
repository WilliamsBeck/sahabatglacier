<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Diskon invoice pembelian (akumulasi satu nota, bukan per item).
 * Prinsip IAS 2 / PSAK 14: diskon pembelian MENGURANGI nilai persediaan —
 * dialokasikan pro-rata ke tiap item, harga batch FIFO = harga NETTO.
 * gross_price_per_base menyimpan harga katalog (bruto) untuk auto-fill
 * "harga terakhir" & tampilan, supaya diskon tidak menular ke input berikutnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mutations', function (Blueprint $table) {
            $table->decimal('discount_amount', 14, 2)->default(0)->after('invoice_no');
        });
        Schema::table('mutation_items', function (Blueprint $table) {
            $table->decimal('gross_price_per_base', 14, 4)->nullable()->after('price_per_base');
        });
    }

    public function down(): void
    {
        Schema::table('mutations', function (Blueprint $table) {
            $table->dropColumn('discount_amount');
        });
        Schema::table('mutation_items', function (Blueprint $table) {
            $table->dropColumn('gross_price_per_base');
        });
    }
};
