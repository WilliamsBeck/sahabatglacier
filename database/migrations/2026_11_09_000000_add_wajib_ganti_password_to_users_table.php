<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PBI-105: tanda "password sementara — wajib diganti saat login".
 * Diisi true oleh aplikasi untuk akun baru & akun yang password-nya di-reset.
 * Akun yang SUDAH ada mendapat false: mereka memakai password pilihan sendiri,
 * jadi tidak dipaksa ganti begitu fitur ini dipasang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('wajib_ganti_password')->default(false)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('wajib_ganti_password');
        });
    }
};
