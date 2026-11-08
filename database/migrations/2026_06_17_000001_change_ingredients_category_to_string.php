<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom ingredients.category sebelumnya enum tetap yang tidak sinkron dengan
 * master "Kategori Bahan Baku" (ingredient_categories) yang dinamis & bisa diedit.
 * Ubah menjadi VARCHAR agar nilai kategori mengikuti master, bukan daftar hardcoded.
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQLite (database test) tidak mengenal MODIFY — pakai Schema builder, hasilnya
        // sama. Jalur MySQL/MariaDB di bawah tidak berubah.
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('ingredients', fn (Blueprint $t) => $t->string('category', 100)->nullable()->change());
            return;
        }
        DB::statement("ALTER TABLE ingredients MODIFY category VARCHAR(100) NULL");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('ingredients', fn (Blueprint $t) =>
                $t->enum('category', ['bubuk', 'teh', 'sirup', 'selai', 'solid', 'kemasan'])->nullable()->change());
            return;
        }
        DB::statement("ALTER TABLE ingredients MODIFY category ENUM('bubuk','teh','sirup','selai','solid','kemasan') NULL");
    }
};
