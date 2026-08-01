<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saklar per bahan: HPP Ideal disamakan dengan HPP Aktual.
 *
 * Untuk bahan seperti Single/Double/Big Bag yang tidak ada di resep menu,
 * HPP Ideal-nya selalu 0 sementara HPP Aktual terisi, sehingga muncul selisih
 * semu yang bukan indikasi boros. Dengan saklar ini, pemakaian aktualnya
 * langsung diakui sebagai ideal dan selisihnya jadi 0.
 *
 * Dibuat sebagai saklar, bukan nama bahan yang di-hardcode, supaya bisa
 * diatur sendiri lewat Master Data -> Bahan tanpa ubah kode.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->boolean('ideal_follows_actual')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropColumn('ideal_follows_actual');
        });
    }
};
