<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Saklar per menu: ikut dihitung ke "TOTAL TERJUAL" di halaman input penjualan?
 *
 * Add on & packaging cup bukan produk jualan, jadi kalau ikut dijumlah angkanya
 * menggelembung. Dibuat sebagai saklar (bukan nama di-hardcode) supaya bisa
 * diatur sendiri lewat Master Data → Menu.
 *
 * Qty-nya TETAP bisa diisi & tersimpan; hanya tidak ikut dijumlah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->boolean('count_in_total')->default(true)->after('is_active');
        });

        // Nilai awal: matikan untuk add on & packaging cup yang sudah ada,
        // supaya langsung sesuai tanpa perlu diatur satu per satu dulu.
        DB::table('menus')
            ->whereIn('category_id', function ($q) {
                $q->select('id')->from('menu_categories')
                  ->whereRaw('LOWER(name) LIKE ?', ['%additional topping%'])
                  ->orWhereRaw('LOWER(name) LIKE ?', ['%add on%'])
                  ->orWhereRaw('LOWER(name) LIKE ?', ['%add-on%']);
            })
            ->orWhereIn('name', ['Packaging Cup 400', 'Packaging Cup 500', 'Packaging Cup 700'])
            ->update(['count_in_total' => false]);
    }

    public function down(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->dropColumn('count_in_total');
        });
    }
};
