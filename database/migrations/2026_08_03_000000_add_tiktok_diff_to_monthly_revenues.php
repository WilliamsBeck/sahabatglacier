<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Omset dipecah jadi: Omset Bruto + Selisih TikTok = Total Omset.
 *
 * Yang disimpan cuma selisihnya. Kolom `total_revenue` TETAP berisi TOTAL
 * (bruto + selisih), jadi semua laporan/HPP yang sudah baca kolom itu tidak
 * perlu diubah dan angkanya otomatis benar.
 *
 * Bruto tidak disimpan terpisah — cukup dihitung: total_revenue - tiktok_diff
 * (lihat accessor `gross_revenue` di model). Dengan begitu tidak ada dua sumber
 * angka yang bisa saling bertentangan.
 *
 * Selisih boleh MINUS (omset TikTok bisa lebih kecil dari catatan kasir).
 * Data lama: selisih = 0, jadi bruto = total, angkanya tidak berubah sama sekali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monthly_revenues', function (Blueprint $table) {
            $table->decimal('tiktok_diff', 15, 2)->default(0)->after('total_revenue');
        });
    }

    public function down(): void
    {
        Schema::table('monthly_revenues', function (Blueprint $table) {
            $table->dropColumn('tiktok_diff');
        });
    }
};
