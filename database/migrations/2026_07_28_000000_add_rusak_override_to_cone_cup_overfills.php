<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Koreksi manual angka "Rusak" di rekonsiliasi Cone & Cup.
 *
 * Angka Rusak aslinya dijumlah dari catatan waste, tapi waste ikut terkunci begitu
 * opname di-approve / HPP dikunci — padahal selisih cup & cone sering baru ketahuan
 * setelah itu. Override ini HANYA mengubah tampilan rekonsiliasi (tidak menyentuh
 * stok/FIFO, karena stok sudah dikoreksi oleh opname fisik), sehingga aman diedit
 * kapan pun. NULL = ikut angka dari catatan waste.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cone_cup_overfills', function (Blueprint $table) {
            $table->decimal('rusak_override', 12, 2)->nullable()->after('qty');
        });
    }

    public function down(): void
    {
        Schema::table('cone_cup_overfills', function (Blueprint $table) {
            $table->dropColumn('rusak_override');
        });
    }
};
