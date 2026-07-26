<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MasterData\StoreController;
use App\Http\Controllers\MasterData\SupplierController;
use App\Http\Controllers\MasterData\IngredientController;
use App\Http\Controllers\MasterData\IngredientPackagingController;
use App\Http\Controllers\MasterData\MenuController;
use App\Http\Controllers\MasterData\RecipeController;
use App\Http\Controllers\MasterData\UserController;
use App\Http\Controllers\MasterData\IngredientCategoryController;
use App\Http\Controllers\MasterData\MenuCategoryController;
use App\Http\Controllers\MasterData\CategoriesController;
use App\Http\Controllers\MasterData\MasterImportController;
use App\Http\Controllers\Inventory\MutationController;
use App\Http\Controllers\Inventory\StockController;
use App\Http\Controllers\Inventory\StockLedgerController;
use App\Http\Controllers\Inventory\DailyLedgerController;
use App\Http\Controllers\Production\ProductionLogController;
use App\Http\Controllers\Production\ProductionAnalysisController;
use App\Http\Controllers\Waste\WasteLogController;
use App\Http\Controllers\Opname\OpnameController;
use App\Http\Controllers\Sales\MonthlySaleController;
use App\Http\Controllers\Sales\HppController;
use App\Http\Controllers\Sales\HppTrendController;
use App\Http\Controllers\Reports\LaporanController;
use App\Http\Controllers\Reports\RingkasanController;
use App\Http\Controllers\Reports\PurchaseReportController;
use App\Http\Controllers\Reports\WasteAnalysisController;
use App\Http\Controllers\Reports\RekapController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrderPlanningController;

// AUTH
Route::get('/login', [LoginController::class, 'showForm'])->name('login');
Route::post('/login', [LoginController::class, 'login']);
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

Route::middleware(['auth'])->group(function () {

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    Route::middleware(['role:super_admin,admin_area'])->prefix('master')->name('master.')->group(function () {
        Route::resource('stores', StoreController::class);
        Route::resource('suppliers', SupplierController::class);
        Route::resource('ingredients', IngredientController::class);
        Route::resource('packagings', IngredientPackagingController::class);
        Route::post('packagings/{packaging}/toggle-active', [IngredientPackagingController::class, 'toggleActive'])->name('packagings.toggle-active');
        Route::resource('menus', MenuController::class);
        Route::delete('menus/{menu}/recipe-version/{group}', [MenuController::class, 'destroyRecipeVersion'])->name('menus.recipe-version.destroy');
        Route::resource('recipes', RecipeController::class);
        Route::get('recipes/{recipe}/duplicate', [RecipeController::class, 'duplicate'])->name('recipes.duplicate');
        Route::resource('users', UserController::class);
        Route::post('users/{user}/assign-store', [UserController::class, 'assignStore'])->name('users.assign-store');
        Route::delete('users/{user}/revoke-store/{store}', [UserController::class, 'revokeStore'])->name('users.revoke-store');
        Route::get('categories', [CategoriesController::class, 'index'])->name('categories.index');
        Route::resource('ingredient-categories', IngredientCategoryController::class)->only(['index','store','update','destroy']);
        Route::post('ingredient-categories/reorder', [IngredientCategoryController::class, 'reorder'])->name('ingredient-categories.reorder');
        Route::resource('menu-categories', MenuCategoryController::class)->only(['index','store','update','destroy']);
        Route::post('menu-categories/reorder', [MenuCategoryController::class, 'reorder'])->name('menu-categories.reorder');

        // Impor massal master data (template + preview + commit)
        Route::get('import/{entity}/template', [MasterImportController::class, 'template'])->name('import.template');
        Route::post('import/{entity}/preview', [MasterImportController::class, 'preview'])->name('import.preview');
        Route::post('import/{entity}/commit',  [MasterImportController::class, 'commit'])->name('import.commit');
        // Bundle multi-sheet (mis. Bahan + Kemasan + Komposisi dalam 1 file)
        Route::get('export/{entity}', [MasterImportController::class, 'exportData'])->name('export');
        Route::get('export-bundle/{bundle}', [MasterImportController::class, 'bundleExportData'])->name('export-bundle');

        Route::get('import-bundle/{bundle}/template', [MasterImportController::class, 'bundleTemplate'])->name('import-bundle.template');
        Route::post('import-bundle/{bundle}/preview', [MasterImportController::class, 'bundlePreview'])->name('import-bundle.preview');
        Route::post('import-bundle/{bundle}/commit',  [MasterImportController::class, 'bundleCommit'])->name('import-bundle.commit');
    });

    // INVENTORI
    Route::middleware(['store.access'])->prefix('inventory')->name('inventory.')->group(function () {
        Route::resource('mutations', MutationController::class);
        Route::post('mutations/{mutation}/confirm', [MutationController::class, 'confirm'])->name('mutations.confirm');
        Route::post('mutations/{mutation}/unconfirm', [MutationController::class, 'unconfirm'])->name('mutations.unconfirm');
        Route::post('mutations/{mutation}/cancel', [MutationController::class, 'cancel'])->name('mutations.cancel');
        Route::get('mutations-export', [MutationController::class, 'export'])->name('mutations.export');
        Route::get('stocks', [StockController::class, 'index'])->name('stocks.index');
        Route::post('stocks/set-store-par', [StockController::class, 'setStorePar'])->name('stocks.set-store-par');
        Route::post('stocks/set-min', [StockController::class, 'setMin'])->name('stocks.set-min');
        Route::get('ledger', [StockLedgerController::class, 'index'])->name('ledger.index');
        Route::get('ledger-export', [StockLedgerController::class, 'export'])->name('ledger.export');
        Route::get('daily-ledger', [DailyLedgerController::class, 'index'])->name('daily-ledger.index');
        Route::post('daily-ledger/save-usage', [DailyLedgerController::class, 'saveUsage'])->name('daily-ledger.save-usage');
        Route::post('daily-ledger/bulk-delete-usage', [DailyLedgerController::class, 'bulkDeleteUsage'])->name('daily-ledger.bulk-delete-usage');
        Route::post('daily-ledger/confirm-date', [DailyLedgerController::class, 'confirmDate'])->name('daily-ledger.confirm-date');
        Route::get('daily-ledger/export-template', [DailyLedgerController::class, 'exportTemplate'])->name('daily-ledger.export-template');
        Route::post('daily-ledger/import-usage', [DailyLedgerController::class, 'importUsage'])->name('daily-ledger.import-usage');
        Route::get('daily-ledger/import-preview', [DailyLedgerController::class, 'importPreview'])->name('daily-ledger.import-preview');
        Route::post('daily-ledger/save-order', [DailyLedgerController::class, 'saveOrder'])->name('daily-ledger.save-order');
        Route::post('daily-ledger/reset-order', [DailyLedgerController::class, 'resetOrder'])->name('daily-ledger.reset-order');
    });

    // PRODUKSI
    Route::middleware(['store.access'])->prefix('production')->name('production.')->group(function () {
        Route::resource('logs', ProductionLogController::class);
        Route::get('logs-export', [ProductionLogController::class, 'export'])->name('logs.export');
        Route::get('analysis', [ProductionAnalysisController::class, 'index'])->name('analysis');
    });

    // WASTE
    Route::middleware(['store.access'])->prefix('waste')->name('waste.')->group(function () {
        Route::resource('logs', WasteLogController::class);
        Route::get('export', [WasteLogController::class, 'export'])->name('logs.export');
    });

    // OPNAME
    Route::middleware(['store.access'])->prefix('opname')->name('opname.')->group(function () {
        // Route statis harus didefinisikan SEBELUM resource agar tidak tertangkap {opname}
        Route::get('opnames/template/download', [OpnameController::class, 'downloadTemplate'])->name('opnames.template');
        Route::get('opnames/import',            [OpnameController::class, 'importForm'])->name('opnames.import.form');
        Route::post('opnames/import',           [OpnameController::class, 'import'])->name('opnames.import');
        Route::resource('opnames', OpnameController::class);
        Route::post('opnames/{opname}/approve', [OpnameController::class, 'approve'])->name('opnames.approve');
        Route::post('opnames/{opname}/unapprove', [OpnameController::class, 'unapprove'])->name('opnames.unapprove');
        Route::post('opnames/{opname}/recalculate', [OpnameController::class, 'recalculate'])->name('opnames.recalculate');
        Route::get('opnames/{opname}/export', [OpnameController::class, 'export'])->name('opnames.export');
        Route::post('opnames/{opname}/add-batch', [OpnameController::class, 'addBatch'])->name('opnames.add-batch');
        Route::delete('opnames/{opname}/items/{item}', [OpnameController::class, 'destroyItem'])->name('opnames.items.destroy');
    });

    // PENJUALAN & HPP
    Route::middleware(['store.access'])->prefix('sales')->name('sales.')->group(function () {
        Route::resource('monthly', MonthlySaleController::class)->only(['index','create','store']);
        Route::get('monthly-export',           [MonthlySaleController::class, 'export'])->name('monthly.export');
        Route::get('monthly-template/download',[MonthlySaleController::class, 'downloadTemplate'])->name('monthly.template');
        Route::get('monthly-import',           [MonthlySaleController::class, 'importForm'])->name('monthly.import.form');
        Route::post('monthly-import/preview',  [MonthlySaleController::class, 'import'])->name('monthly.import');
        Route::post('monthly-import/commit',   [MonthlySaleController::class, 'importCommit'])->name('monthly.import.commit');
        // Group-level (per store+periode)
        Route::get('period/show',    [MonthlySaleController::class, 'periodShow'])->name('period.show');
        Route::get('period/edit',    [MonthlySaleController::class, 'periodEdit'])->name('period.edit');
        Route::put('period/update',  [MonthlySaleController::class, 'periodUpdate'])->name('period.update');
        Route::delete('period/destroy', [MonthlySaleController::class, 'periodDestroy'])->name('period.destroy');
        Route::get('hpp', [HppController::class, 'index'])->name('hpp.index');
        Route::post('hpp/lock', [HppController::class, 'lock'])->name('hpp.lock');
        Route::post('hpp/unlock', [HppController::class, 'unlock'])->name('hpp.unlock');
        Route::get('hpp/compare', [HppController::class, 'compare'])->name('hpp.compare');
        Route::get('hpp/trend', [HppTrendController::class, 'index'])->name('hpp.trend');
        Route::get('hpp/cone-cup', [HppController::class, 'coneCup'])->name('hpp.cone-cup');
        Route::post('hpp/cone-cup/save', [HppController::class, 'saveOverfill'])->name('hpp.cone-cup.save');
        Route::get('hpp/export', [HppController::class, 'export'])->name('hpp.export');
    });

    // LAPORAN
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('ringkasan', [RingkasanController::class, 'index'])->name('ringkasan');
        Route::get('ringkasan/export', [RingkasanController::class, 'export'])->name('ringkasan.export');
        Route::get('purchase', [PurchaseReportController::class, 'index'])->name('purchase');
        Route::get('waste', [WasteAnalysisController::class, 'index'])->name('waste');
        Route::get('export/waste', [RekapController::class, 'exportWaste'])->name('export.waste');
        Route::get('export/purchase', [RekapController::class, 'exportPurchase'])->name('export.purchase');
        Route::get('export/hpp', [RekapController::class, 'exportHpp'])->name('export.hpp');

        // Laporan Detail
        Route::prefix('laporan')->name('laporan.')->group(function () {
            Route::get('/', [LaporanController::class, 'index'])->name('index');
            Route::get('menu-terjual', [LaporanController::class, 'menuTerjual'])->name('menu-terjual');
            Route::get('menu-terjual/export', [LaporanController::class, 'exportMenuTerjual'])->name('menu-terjual.export');
            Route::get('hpp', [LaporanController::class, 'hpp'])->name('hpp');
            Route::get('hpp/export', [LaporanController::class, 'exportHpp'])->name('hpp.export');
            Route::get('waste', [LaporanController::class, 'waste'])->name('waste');
            Route::get('waste/export', [LaporanController::class, 'exportWaste'])->name('waste.export');
            Route::get('produksi', [LaporanController::class, 'produksi'])->name('produksi');
            Route::get('produksi/export', [LaporanController::class, 'exportProduksi'])->name('produksi.export');
            Route::get('mutasi-stok', [LaporanController::class, 'mutasiStok'])->name('mutasi-stok');
            Route::get('mutasi-stok/export', [LaporanController::class, 'exportMutasiStok'])->name('mutasi-stok.export');
        });
    });

    // AUDIT LOG
    Route::middleware(['role:super_admin'])->prefix('audit')->name('audit.')->group(function () {
        Route::get('/', [AuditLogController::class, 'index'])->name('index');
        Route::get('{auditLog}', [AuditLogController::class, 'show'])->name('show');
    });

    // NOTIFIKASI (API internal)
    Route::prefix('notifications-api')->name('notifications.')->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::post('mark-read', [NotificationController::class, 'markRead'])->name('mark-read');
        Route::post('generate-low-stock', [NotificationController::class, 'generateLowStock'])->name('generate-low-stock');
    });

    // ORDER PLANNING
    Route::middleware(['store.access'])->group(function () {
        Route::get('/order-planning', [OrderPlanningController::class, 'index'])->name('order-planning.index');
        Route::get('/order-planning/export', [OrderPlanningController::class, 'export'])->name('order-planning.export');
    });

    // API INTERNAL (AJAX)
    Route::prefix('api-internal')->name('api.')->group(function () {
        Route::get('ingredient/{ingredient}/last-price', [MutationController::class, 'lastPrice'])->name('ingredient.last-price');
        Route::get('ingredient/{ingredient}/stock-price', [MutationController::class, 'stockPrice'])->name('ingredient.stock-price');
        Route::get('store/{store}/stock-summary', [MutationController::class, 'storeStockSummary'])->name('store.stock-summary');
        Route::get('ingredient/{ingredient}/packagings', [IngredientController::class, 'packagings'])->name('ingredient.packagings');
        Route::get('ingredient/{ingredient}/compositions', [IngredientController::class, 'compositions'])->name('ingredient.compositions');
        Route::get('opname/system-qty', [OpnameController::class, 'systemQty'])->name('opname.system-qty');
    });
});
