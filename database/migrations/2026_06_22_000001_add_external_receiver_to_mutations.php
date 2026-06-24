<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mutations', function (Blueprint $table) {
            // Nama penerima/pembeli untuk Penjualan Eksternal (pihak luar, diketik manual).
            // Pasangan arah-keluar dari external_sender (yang dipakai arah masuk).
            $table->string('external_receiver')->nullable()->after('external_sender');
        });
    }

    public function down(): void
    {
        Schema::table('mutations', function (Blueprint $table) {
            $table->dropColumn('external_receiver');
        });
    }
};
