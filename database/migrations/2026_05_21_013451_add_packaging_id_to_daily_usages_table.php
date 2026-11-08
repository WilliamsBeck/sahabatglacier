<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        if (!Schema::hasColumn('daily_usages', 'packaging_id')) {
            Schema::table('daily_usages', function (Blueprint $table) {
                $table->foreignId('packaging_id')->nullable()->after('ingredient_id')
                      ->constrained('ingredient_packagings')->nullOnDelete();
            });
        }
        // SQLite (database test) tidak mengenal SHOW INDEX / ADD UNIQUE INDEX — pakai
        // Schema builder, hasilnya sama. Jalur MySQL/MariaDB di bawah tidak berubah.
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('daily_usages', function (Blueprint $table) {
                if (!Schema::hasIndex('daily_usages', 'daily_usages_unique')) {
                    $table->unique(['store_id', 'ingredient_id', 'packaging_id', 'usage_date'], 'daily_usages_unique');
                }
                if (Schema::hasIndex('daily_usages', 'daily_usages_store_id_ingredient_id_usage_date_unique')) {
                    $table->dropUnique('daily_usages_store_id_ingredient_id_usage_date_unique');
                }
            });
            return;
        }

        if (!$this->indexExists('daily_usages', 'daily_usages_unique')) {
            DB::statement('ALTER TABLE daily_usages ADD UNIQUE INDEX daily_usages_unique (store_id, ingredient_id, packaging_id, usage_date)');
        }
        if ($this->indexExists('daily_usages', 'daily_usages_store_id_ingredient_id_usage_date_unique')) {
            DB::statement('ALTER TABLE daily_usages DROP INDEX daily_usages_store_id_ingredient_id_usage_date_unique');
        }
    }

    public function down(): void {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('daily_usages', function (Blueprint $table) {
                $table->unique(['store_id', 'ingredient_id', 'usage_date'], 'daily_usages_store_id_ingredient_id_usage_date_unique');
                $table->dropUnique('daily_usages_unique');
                $table->dropForeign(['packaging_id']);
                $table->dropColumn('packaging_id');
            });
            return;
        }

        DB::statement('ALTER TABLE daily_usages ADD UNIQUE INDEX daily_usages_store_id_ingredient_id_usage_date_unique (store_id, ingredient_id, usage_date)');
        if ($this->indexExists('daily_usages', 'daily_usages_unique')) {
            DB::statement('ALTER TABLE daily_usages DROP INDEX daily_usages_unique');
        }
        Schema::table('daily_usages', function (Blueprint $table) {
            $table->dropForeign(['packaging_id']);
            $table->dropColumn('packaging_id');
        });
    }

    private function indexExists(string $table, string $index): bool {
        $indexes = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$index]);
        return count($indexes) > 0;
    }
};
