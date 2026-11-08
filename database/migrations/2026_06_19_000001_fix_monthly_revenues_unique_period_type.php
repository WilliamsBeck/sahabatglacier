<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Unique index 'mr_unique' awalnya (store_id, month, year) — sebelum kolom
     * period_type ditambahkan. Akibatnya end_month & mid_month untuk periode yang
     * sama bentrok. Perbaiki agar menyertakan period_type.
     *
     * mr_unique dipakai sebagai index untuk FK store_id, jadi FK harus dilepas
     * dulu sebelum index bisa diganti.
     */
    public function up(): void
    {
        // SQLite (database test): FK tidak bergantung pada index ini & tidak bisa dilepas
        // per nama — cukup ganti unique index-nya. Jalur MySQL/MariaDB di bawah tidak berubah.
        if (\Illuminate\Support\Facades\DB::getDriverName() === 'sqlite') {
            Schema::table('monthly_revenues', function (Blueprint $table) {
                $table->dropUnique('mr_unique');
                $table->unique(['store_id', 'month', 'year', 'period_type'], 'mr_unique');
            });
            return;
        }

        Schema::table('monthly_revenues', function (Blueprint $table) {
            $table->dropForeign('monthly_revenues_store_id_foreign');
            $table->dropUnique('mr_unique');
            $table->unique(['store_id', 'month', 'year', 'period_type'], 'mr_unique');
            $table->foreign('store_id')->references('id')->on('stores');
        });
    }

    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::getDriverName() === 'sqlite') {
            Schema::table('monthly_revenues', function (Blueprint $table) {
                $table->dropUnique('mr_unique');
                $table->unique(['store_id', 'month', 'year'], 'mr_unique');
            });
            return;
        }

        Schema::table('monthly_revenues', function (Blueprint $table) {
            $table->dropForeign('monthly_revenues_store_id_foreign');
            $table->dropUnique('mr_unique');
            $table->unique(['store_id', 'month', 'year'], 'mr_unique');
            $table->foreign('store_id')->references('id')->on('stores');
        });
    }
};
