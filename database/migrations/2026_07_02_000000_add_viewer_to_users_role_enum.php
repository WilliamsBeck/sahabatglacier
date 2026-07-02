<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE users MODIFY role ENUM('super_admin', 'admin_area', 'viewer') NOT NULL DEFAULT 'admin_area'");
    }

    public function down(): void
    {
        // Kembalikan viewer ke admin_area sebelum mempersempit enum agar tidak truncate.
        DB::statement("UPDATE users SET role = 'admin_area' WHERE role = 'viewer'");
        DB::statement("ALTER TABLE users MODIFY role ENUM('super_admin', 'admin_area') NOT NULL DEFAULT 'admin_area'");
    }
};
