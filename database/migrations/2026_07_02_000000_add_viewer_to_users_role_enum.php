<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite (database test) tidak mengenal MODIFY — pakai Schema builder, hasilnya
        // sama. Jalur MySQL/MariaDB di bawah tidak berubah.
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('users', fn (Blueprint $t) =>
                $t->enum('role', ['super_admin', 'admin_area', 'viewer'])->default('admin_area')->change());
            return;
        }
        DB::statement("ALTER TABLE users MODIFY role ENUM('super_admin', 'admin_area', 'viewer') NOT NULL DEFAULT 'admin_area'");
    }

    public function down(): void
    {
        // Kembalikan viewer ke admin_area sebelum mempersempit enum agar tidak truncate.
        DB::statement("UPDATE users SET role = 'admin_area' WHERE role = 'viewer'");
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('users', fn (Blueprint $t) =>
                $t->enum('role', ['super_admin', 'admin_area'])->default('admin_area')->change());
            return;
        }
        DB::statement("ALTER TABLE users MODIFY role ENUM('super_admin', 'admin_area') NOT NULL DEFAULT 'admin_area'");
    }
};
