<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            // Safety stock dalam HARI — cadangan di atas lead time (reorder point).
            // ROP = lead_time + safety_stock. Menggantikan buffer tebakan 15% siklus order.
            $table->tinyInteger('safety_stock_days')->unsigned()->nullable()->after('order_cycle_days');
        });

        // Backfill toko yang sudah punya lead time: default 40% lead time (min 1 hari),
        // supaya perilaku langsung berguna & bisa diedit sendiri oleh user.
        DB::table('stores')->whereNotNull('lead_time_days')->where('lead_time_days', '>', 0)
            ->update(['safety_stock_days' => DB::raw('GREATEST(1, ROUND(lead_time_days * 0.4))')]);
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('safety_stock_days');
        });
    }
};
