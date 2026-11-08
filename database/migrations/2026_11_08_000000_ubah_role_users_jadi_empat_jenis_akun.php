<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PBI-104 / PBI-027 — jenis akun menjadi empat:
 *   super_admin, manager, admin_area, supervisor. Role 'viewer' dihapus.
 *
 * - Akun viewer (hanya-lihat) dipindah ke supervisor: sama-sama hanya bisa melihat,
 *   bedanya supervisor dibatasi ke toko yang di-assign.
 * - Akun super_admin TIDAK diubah otomatis. Siapa yang menjadi manager ditentukan
 *   pemilik lewat Master User.
 *
 * Urutan: perlebar enum dulu (nilai lama + baru sama-sama sah) → pindahkan data →
 * persempit. Kalau langsung dipersempit, baris 'viewer' akan ditolak/terpotong.
 * Memakai Schema builder (->change()) supaya sama di MySQL/MariaDB dan SQLite (test).
 */
return new class extends Migration
{
    private const LENGKAP = ['super_admin', 'manager', 'admin_area', 'supervisor', 'viewer'];
    private const BARU    = ['super_admin', 'manager', 'admin_area', 'supervisor'];
    private const LAMA    = ['super_admin', 'admin_area', 'viewer'];

    public function up(): void
    {
        $this->ubahEnum(self::LENGKAP);
        DB::table('users')->where('role', 'viewer')->update(['role' => 'supervisor']);
        $this->ubahEnum(self::BARU);
    }

    public function down(): void
    {
        $this->ubahEnum(self::LENGKAP);
        DB::table('users')->where('role', 'supervisor')->update(['role' => 'viewer']);
        // Manager tidak punya padanan di skema lama — turun ke admin_area (hak paling
        // dekat & tidak melebar), BUKAN naik ke super_admin.
        DB::table('users')->where('role', 'manager')->update(['role' => 'admin_area']);
        $this->ubahEnum(self::LAMA);
    }

    private function ubahEnum(array $nilai): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->enum('role', $nilai)->default('admin_area')->change());
    }
};
