<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cone_cup_overfills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('month');
            $table->unsignedSmallInteger('year');
            $table->decimal('qty', 12, 2)->default(0);   // overfill (pcs) — input manual
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            // satu nilai overfill per (toko, bahan, bulan, tahun)
            $table->unique(['store_id', 'ingredient_id', 'month', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cone_cup_overfills');
    }
};
