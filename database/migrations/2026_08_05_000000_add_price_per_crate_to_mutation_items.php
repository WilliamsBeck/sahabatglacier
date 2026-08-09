<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Simpan HARGA PER DUS apa adanya — tanpa konversi bolak-balik.
 *
 * Sebelumnya harga/dus yang diketik user langsung dibagi jadi price_per_base,
 * lalu ditampilkan ulang dengan dikalikan lagi. Kolom gross_price_per_base cuma
 * decimal(14,4), jadi hasil bolak-baliknya meleset:
 *
 *   Brown Sugar Syrup, isi/dus 30.000
 *   730.000 / 30.000 = 24,3333 (4 desimal)  ->  x30.000 = 729.999   <- salah
 *
 * Efek sampingnya: user mengedit harga jadi 730.000, disimpan, lalu ditampilkan
 * 729.999 lagi — terlihat seperti "editnya tidak tersimpan".
 *
 * Sekarang angka yang diketik disimpan utuh di price_per_crate dan itu pula yang
 * ditampilkan. price_per_base TETAP ada (diturunkan dari harga/dus) karena FIFO,
 * HPP, dan valuasi stok semuanya berhitung dalam satuan dasar.
 *
 * gross_price_per_base ikut dinaikkan ke 8 desimal supaya jalur lama (alokasi
 * diskon invoice) tidak lagi memangkas presisi.
 *
 * Data lama SENGAJA tidak diisi otomatis — user memilih mengoreksinya sendiri
 * lewat fitur Edit di web. Selama price_per_crate masih NULL, tampilan tetap
 * memakai perhitungan lama, jadi tidak ada yang berubah sampai baris itu diedit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mutation_items', function (Blueprint $table) {
            $table->decimal('price_per_crate', 16, 2)->nullable()->after('price_per_base');
            $table->decimal('gross_price_per_base', 20, 8)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('mutation_items', function (Blueprint $table) {
            $table->dropColumn('price_per_crate');
            $table->decimal('gross_price_per_base', 14, 4)->nullable()->change();
        });
    }
};
