<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Urutan baris Pencatatan Harian — kini melekat ke TOKO (bukan per user) dan
 * dikunci per (bahan × kemasan), supaya tiap baris kemasan bisa diatur sendiri.
 * Menggantikan peran user_ingredient_orders (tabel lama dibiarkan, tidak dipakai).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_ingredient_orders', function (Blueprint $table) {
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            // 0 = bahan tanpa kemasan. Tidak nullable supaya bisa jadi primary key.
            $table->unsignedBigInteger('packaging_id')->default(0);
            $table->unsignedSmallInteger('sort_order');
            $table->primary(['store_id', 'ingredient_id', 'packaging_id'], 'sio_primary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_ingredient_orders');
    }
};
