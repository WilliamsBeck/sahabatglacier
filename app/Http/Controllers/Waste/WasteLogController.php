<?php
namespace App\Http\Controllers\Waste;
use App\Http\Controllers\Controller;
use App\Models\{WasteLog, WasteLogItem, Ingredient, IngredientPackaging, Store};
use App\Services\{FifoService, StockLedgerService};
use App\Exports\ArrayExport;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WasteLogController extends Controller
{
    public function index(Request $request)
    {
        $storeIds = auth()->user()->accessibleStoreIds();
        $query    = WasteLog::with('store')->whereIn('store_id',$storeIds);
        if ($request->store_id)  $query->where('store_id',$request->store_id);
        if ($request->date_from) $query->where('waste_date','>=',$request->date_from);
        if ($request->date_to)   $query->where('waste_date','<=',$request->date_to);
        $logs   = $query->with('items.ingredient')->latest('waste_date')->paginate(20);
        $stores = auth()->user()->accessibleStores();
        return view('waste.index', compact('logs','stores'));
    }

    // ── Export Excel daftar waste (per bahan) ────────────────────────────────
    public function export(Request $request)
    {
        $storeIds = auth()->user()->accessibleStoreIds();
        $query = WasteLog::with(['store', 'items.ingredient', 'items.packaging'])
            ->whereIn('store_id', $storeIds);
        if ($request->store_id)  $query->where('store_id', $request->store_id);
        if ($request->date_from) $query->where('waste_date', '>=', $request->date_from);
        if ($request->date_to)   $query->where('waste_date', '<=', $request->date_to);
        $logs = $query->orderBy('waste_date')->get();

        $data = [[
            'Tanggal', 'Toko', 'Bahan', 'Dus', 'Pack', 'Pcs/Gr',
            'Harga/Satuan', 'Nilai Rugi (Rp)', 'Catatan',
        ]];

        $totalLoss = 0;
        foreach ($logs as $log) {
            foreach ($log->items as $it) {
                $totalLoss += (float) $it->subtotal_loss;
                $data[] = [
                    $log->waste_date->format('d/m/Y'),
                    $log->store->name ?? '-',
                    $it->ingredient->name ?? '-',
                    (int) ($it->qty_crate ?? 0),
                    (int) ($it->qty_pack ?? 0),
                    (float) ($it->qty_base ?? 0),
                    (float) ($it->price_per_base ?? 0),
                    (float) $it->subtotal_loss,
                    $log->notes ?? '',
                ];
            }
        }

        $data[] = ['', '', '', '', '', '', 'TOTAL', $totalLoss, ''];

        $filename = 'Daftar-Waste_' . now()->format('Ymd-His') . '.xlsx';
        return Excel::download(new ArrayExport($data), $filename);
    }

    public function create()
    {
        $stores      = auth()->user()->accessibleStores();
        $ingredients = Ingredient::with([
                'packagings'   => fn($q) => $q->where('is_active', true)->orderBy('id'),
                'compositions.child',
            ])
            ->where('ingredients.is_active', true)
            ->orderedByCategory()   // kategori → urutan input (id); grouping type diatur blade
            ->get();

        // Data untuk JS: ingredient + packagings + compositions
        $ingredientJs = $ingredients->map(fn($i) => [
            'id'       => $i->id,
            'name'     => $i->name,
            'type'     => $i->type,
            'unit'     => $i->unit_base,
            'packagings' => $i->packagings->map(fn($p) => [
                'id'             => $p->id,
                'packaging_name' => $p->packaging_name,
                'crate_to_pack'  => $p->crate_to_pack,
                'pack_to_base'   => $p->pack_to_base,
            ])->values()->all(),
            // Komposisi resep (hanya untuk semi_finished)
            'compositions' => $i->type === 'semi_finished'
                ? $i->compositions->map(fn($c) => [
                    'child_id'   => $c->child_id,
                    'child_name' => $c->child->name ?? '?',
                    'qty_needed' => (float) $c->qty_needed_exact,  // qty bahan baku per 1 unit semi_finished
                ])->values()->all()
                : [],
        ])->values()->all();

        return view('waste.create', compact('stores', 'ingredients', 'ingredientJs'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'store_id'                    => 'required|exists:stores,id',
            'waste_date'                  => 'required|date',
            'notes'                       => 'nullable|string',
            'items'                       => 'nullable|array',
            'items.*.ingredient_id'       => 'required|exists:ingredients,id',
            'items.*.packaging_id'        => 'nullable|exists:ingredient_packagings,id',
            'items.*.qty_crate'           => 'nullable|integer|min:0',
            'items.*.qty_pack'            => 'nullable|integer|min:0',
            'items.*.qty_base'            => 'nullable|numeric|min:0',
            'items.*.nominal_loss'        => 'nullable|numeric|min:0',
            'reworks'                     => 'nullable|array',
            'reworks.*.ingredient_id'     => 'required_with:reworks|exists:ingredients,id',
            'reworks.*.packaging_id'      => 'nullable|exists:ingredient_packagings,id',
            'reworks.*.qty_crate'         => 'nullable|integer|min:0',
            'reworks.*.qty_pack'          => 'nullable|integer|min:0',
            'reworks.*.qty_base'          => 'nullable|numeric|min:0',
        ]);

        if (empty($request->items) && empty($request->reworks)) {
            return back()->withInput()->withErrors(['items' => 'Isi minimal satu bahan (terbuang atau rusak bisa dipakai lagi).']);
        }

        // Lock periode oleh opname: waste pada/sebelum tgl opname approved ditolak
        if (\App\Models\Opname::isDateLocked((int)$request->store_id, $request->waste_date)) {
            return back()->withInput()->with('error', \App\Models\Opname::lockMessageFor((int)$request->store_id));
        }
        // Lock periode oleh snapshot HPP (konsisten dengan mutasi & pencatatan harian)
        $wc = \Carbon\Carbon::parse($request->waste_date);
        if (\App\Models\HppSnapshot::isDateLocked((int)$request->store_id, $request->waste_date)) {
            return back()->withInput()->with('error',
                \App\Models\HppSnapshot::lockMessageFor((int)$request->store_id, $wc->month, $wc->year));
        }

        try {
        DB::transaction(function () use ($request) {
            $storeId       = $request->store_id;
            $totalLoss     = 0;
            $deductedSoFar = []; // kumulatif per ingId dalam satu submit ini

            // Pre-fetch saldo stok semua ingredient yang terlibat (waste + rework)
            $allItems = array_merge($request->items ?? [], $request->reworks ?? []);
            $ingIds   = array_values(array_unique(array_filter(
                array_map(fn($i) => (int)($i['ingredient_id'] ?? 0), $allItems)
            )));
            // Sisa stok FIFO PER (ingredient × packaging) — cek waste harus per kemasan,
            // bukan per bahan (kemasan stok 0 tidak boleh di-waste walau kemasan lain ada).
            $remainingByPkg = [];
            foreach (\App\Models\MutationItem::whereHas('mutation', fn($q) =>
                        $q->where('destination_store_id', $storeId)->where('status', 'confirmed'))
                    ->whereIn('ingredient_id', $ingIds)->where('remaining_qty', '>', 0)
                    ->get(['ingredient_id', 'packaging_id', 'remaining_qty']) as $b) {
                $k = $b->ingredient_id . '-' . ($b->packaging_id ?: '0');
                $remainingByPkg[$k] = ($remainingByPkg[$k] ?? 0) + (float) $b->remaining_qty;
            }

            // Helper: cek stok kemasan sebelum deduct; lempar exception jika 0/tidak cukup
            $checkAndTrack = function (Ingredient $ing, $packagingId, float $stockBase) use (&$deductedSoFar, $remainingByPkg) {
                if ($stockBase <= 0) return;
                $k           = $ing->id . '-' . ($packagingId ?: '0');
                $available   = (float)($remainingByPkg[$k] ?? 0);
                $alreadySpent = $deductedSoFar[$k] ?? 0.0;
                if ($stockBase > ($available - $alreadySpent) + 0.001) {
                    throw new \RuntimeException('STOCK_ERROR: Stok "' . $ing->name . '" pada kemasan ini tidak mencukupi. '
                        . 'Tersedia: ' . number_format($available - $alreadySpent, 2, ',', '.') . ' ' . $ing->unit_base
                        . ', dibutuhkan: ' . number_format($stockBase, 2, ',', '.') . ' ' . $ing->unit_base . '.');
                }
                $deductedSoFar[$k] = $alreadySpent + $stockBase;
            };

            $log = WasteLog::create([
                'store_id'          => $storeId,
                'waste_date'        => $request->waste_date,
                'notes'             => $request->notes,
                'total_loss_amount' => 0,
                'recorded_by'       => auth()->id(),
            ]);

            foreach ($request->items ?? [] as $item) {
                $ingredient = Ingredient::find($item['ingredient_id']);
                abort_if(!$ingredient, 422, 'Bahan tidak ditemukan.');

                $packaging = !empty($item['packaging_id'])
                    ? IngredientPackaging::find($item['packaging_id']) : null;

                $qtyCrate = (int)($item['qty_crate'] ?? 0);
                $qtyPack  = (int)($item['qty_pack']  ?? 0);
                $qtyBase  = (float)($item['qty_base'] ?? 0);

                $totalBase = $packaging
                    ? $packaging->convertToBase($qtyCrate, $qtyPack, $qtyBase)
                    : $qtyBase;

                if ($totalBase <= 0) continue;

                // Porsi yang memotong stok (Dus+Pack saja; pcs/gr tidak).
                // Bahan setengah jadi TIDAK distok (produksi data-only) → stockBase = 0,
                // sehingga ketersediaan tidak dicek & saldo stok tidak dipotong.
                $stockBase = $ingredient->isRaw()
                    ? ($packaging ? $packaging->convertToBase($qtyCrate, $qtyPack, 0) : $totalBase)
                    : 0;
                $checkAndTrack($ingredient, $item['packaging_id'] ?? null, $stockBase); // cek stok per kemasan (bahan baku)

                if ($ingredient->isRaw()) {
                    $price    = FifoService::getCost($storeId, $ingredient->id, $totalBase, $item['packaging_id'] ?? null) / max($totalBase, 0.0001);
                    $subtotal = $totalBase * $price;

                    $log->items()->create([
                        'source_type'          => 'raw',
                        'source_ingredient_id' => $ingredient->id,
                        'source_qty'           => $totalBase,
                        'packaging_id'         => $item['packaging_id'] ?? null,
                        'qty_crate'            => $qtyCrate ?: null,
                        'qty_pack'             => $qtyPack  ?: null,
                        'ingredient_id'        => $ingredient->id,
                        'qty_base'             => $totalBase,
                        'price_per_base'       => $price,
                        'subtotal_loss'        => $subtotal,
                    ]);
                    if ($stockBase > 0) {
                        StockLedgerService::record($storeId, $ingredient->id, $request->waste_date, 'waste', -$stockBase, 'WasteLog', $log->id);
                        FifoService::deduct($storeId, $ingredient->id, $stockBase, $item['packaging_id'] ?? null);
                    }
                    $totalLoss += $subtotal;

                } else {
                    $ingredient->loadMissing('compositions');
                    $nominalLoss = 0;

                    foreach ($ingredient->compositions as $comp) {
                        $rawQtyNeeded = $comp->qty_needed_exact * $totalBase;
                        $rawCost      = FifoService::getCost($storeId, $comp->child_id, $rawQtyNeeded);
                        $nominalLoss += $rawCost;
                    }

                    if ($nominalLoss == 0) {
                        $nominalLoss = (float)($item['nominal_loss'] ?? 0);
                    }

                    $pricePerBase = $totalBase > 0 ? $nominalLoss / $totalBase : 0;

                    $log->items()->create([
                        'source_type'          => 'semi_finished',
                        'source_ingredient_id' => $ingredient->id,
                        'source_qty'           => $totalBase,
                        'packaging_id'         => $item['packaging_id'] ?? null,
                        'qty_crate'            => $qtyCrate ?: null,
                        'qty_pack'             => $qtyPack  ?: null,
                        'ingredient_id'        => $ingredient->id,
                        'qty_base'             => $totalBase,
                        'price_per_base'       => $pricePerBase,
                        'subtotal_loss'        => $nominalLoss,
                    ]);
                    if ($stockBase > 0) {
                        StockLedgerService::record($storeId, $ingredient->id, $request->waste_date, 'waste', -$stockBase, 'WasteLog', $log->id);
                        FifoService::deduct($storeId, $ingredient->id, $stockBase, $item['packaging_id'] ?? null);
                    }
                    $totalLoss += $nominalLoss;
                }
            }

            // ── Rework items — potong stok, kerugian = 0 ──────────────
            foreach ($request->reworks ?? [] as $item) {
                $ingredient = Ingredient::find($item['ingredient_id']);
                if (!$ingredient) continue;

                $packaging = !empty($item['packaging_id'])
                    ? IngredientPackaging::find($item['packaging_id']) : null;

                $qtyCrate  = (int)($item['qty_crate'] ?? 0);
                $qtyPack   = (int)($item['qty_pack']  ?? 0);
                $qtyBase   = (float)($item['qty_base'] ?? 0);
                $totalBase = $packaging
                    ? $packaging->convertToBase($qtyCrate, $qtyPack, $qtyBase)
                    : $qtyBase;

                if ($totalBase <= 0) continue;

                // Semi-finished tidak distok → stockBase = 0 (tidak dicek, tidak dipotong)
                $stockBase = $ingredient->isRaw()
                    ? ($packaging ? $packaging->convertToBase($qtyCrate, $qtyPack, 0) : $totalBase)
                    : 0;
                $checkAndTrack($ingredient, $item['packaging_id'] ?? null, $stockBase); // validasi kumulatif per kemasan

                $log->items()->create([
                    'source_type'          => $ingredient->isRaw() ? 'raw' : 'semi_finished',
                    'source_ingredient_id' => $ingredient->id,
                    'source_qty'           => $totalBase,
                    'packaging_id'         => $item['packaging_id'] ?? null,
                    'qty_crate'            => $qtyCrate ?: null,
                    'qty_pack'             => $qtyPack  ?: null,
                    'ingredient_id'        => $ingredient->id,
                    'qty_base'             => $totalBase,
                    'price_per_base'       => 0,
                    'subtotal_loss'        => 0,
                    'is_rework'            => true,
                ]);
                if ($stockBase > 0) {
                    StockLedgerService::record($storeId, $ingredient->id, $request->waste_date, 'waste', -$stockBase, 'WasteLog', $log->id);
                    FifoService::deduct($storeId, $ingredient->id, $stockBase, $item['packaging_id'] ?? null);
                }
            }

            $log->update(['total_loss_amount' => $totalLoss]);
        });
        } catch (\RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'STOCK_ERROR: ')) {
                return back()->withInput()->withErrors(['stock_error' => substr($e->getMessage(), 13)]);
            }
            throw $e;
        }

        return redirect()->route('waste.logs.index')->with('success','Waste berhasil dicatat.');
    }

    public function show(WasteLog $log)
    {
        $log->load(['store','items.sourceIngredient','items.ingredient','items.packaging','recordedBy']);
        return view('waste.show', compact('log'));
    }

    public function edit(WasteLog $log)
    {
        $log->load('items.packaging');

        $stores      = auth()->user()->accessibleStores();
        $ingredients = Ingredient::with([
                'packagings'   => fn($q) => $q->where('is_active', true)->orderBy('id'),
                'compositions.child',
            ])
            ->where('ingredients.is_active', true)
            ->orderedByCategory()
            ->get();

        $ingredientJs = $ingredients->map(fn($i) => [
            'id'         => $i->id,
            'name'       => $i->name,
            'type'       => $i->type,
            'unit'       => $i->unit_base,
            'packagings' => $i->packagings->map(fn($p) => [
                'id'             => $p->id,
                'packaging_name' => $p->packaging_name,
                'crate_to_pack'  => $p->crate_to_pack,
                'pack_to_base'   => $p->pack_to_base,
            ])->values()->all(),
            'compositions' => $i->type === 'semi_finished'
                ? $i->compositions->map(fn($c) => [
                    'child_id'   => $c->child_id,
                    'child_name' => $c->child->name ?? '?',
                    'qty_needed' => (float) $c->qty_needed_exact,
                ])->values()->all()
                : [],
        ])->values()->all();

        // Helper: hitung direct base (sisa setelah dikurangi porsi dus & pack)
        $calcDirectBase = function ($item) {
            $pkg        = $item->packaging;
            $directBase = (float) $item->qty_base;
            if ($pkg) {
                $directBase -= ($item->qty_crate ?? 0) * $pkg->crate_to_pack * $pkg->pack_to_base;
                $directBase -= ($item->qty_pack  ?? 0) * $pkg->pack_to_base;
                $directBase  = max(0, round($directBase, 4));
            }
            return $directBase > 0.001 ? $directBase : 0;
        };

        // Pre-fill waste items (is_rework = false)
        $prefilledItems = $log->items->where('is_rework', false)->values()
            ->map(fn($item, $idx) => [
                'idx'           => $idx,
                'ingredient_id' => $item->ingredient_id,
                'packaging_id'  => $item->packaging_id,
                'qty_crate'     => $item->qty_crate,
                'qty_pack'      => $item->qty_pack,
                'qty_base'      => $calcDirectBase($item),
            ])->all();

        // Pre-fill rework items (is_rework = true)
        $prefilledReworks = $log->items->where('is_rework', true)->values()
            ->map(fn($item, $idx) => [
                'idx'           => $idx,
                'ingredient_id' => $item->ingredient_id,
                'packaging_id'  => $item->packaging_id,
                'qty_crate'     => $item->qty_crate,
                'qty_pack'      => $item->qty_pack,
                'qty_base'      => $calcDirectBase($item),
            ])->all();

        return view('waste.edit', compact('log', 'stores', 'ingredients', 'ingredientJs', 'prefilledItems', 'prefilledReworks'));
    }

    public function update(Request $request, WasteLog $log)
    {
        $request->validate([
            'waste_date'                  => 'required|date',
            'notes'                       => 'nullable|string',
            'items'                       => 'nullable|array',
            'items.*.ingredient_id'       => 'required|exists:ingredients,id',
            'items.*.packaging_id'        => 'nullable|exists:ingredient_packagings,id',
            'items.*.qty_crate'           => 'nullable|integer|min:0',
            'items.*.qty_pack'            => 'nullable|integer|min:0',
            'items.*.qty_base'            => 'nullable|numeric|min:0',
            'items.*.nominal_loss'        => 'nullable|numeric|min:0',
            'reworks'                     => 'nullable|array',
            'reworks.*.ingredient_id'     => 'required_with:reworks|exists:ingredients,id',
            'reworks.*.packaging_id'      => 'nullable|exists:ingredient_packagings,id',
            'reworks.*.qty_crate'         => 'nullable|integer|min:0',
            'reworks.*.qty_pack'          => 'nullable|integer|min:0',
            'reworks.*.qty_base'          => 'nullable|numeric|min:0',
        ]);

        if (empty($request->items) && empty($request->reworks)) {
            return back()->withInput()->withErrors(['items' => 'Isi minimal satu bahan (terbuang atau rusak bisa dipakai lagi).']);
        }

        // Lock periode oleh opname (tanggal lama maupun baru)
        if (\App\Models\Opname::isDateLocked($log->store_id, $request->waste_date)
            || \App\Models\Opname::isDateLocked($log->store_id, $log->waste_date->format('Y-m-d'))) {
            return back()->withInput()->with('error', \App\Models\Opname::lockMessageFor($log->store_id));
        }
        // Lock periode oleh snapshot HPP (tanggal lama maupun baru)
        $wc = \Carbon\Carbon::parse($request->waste_date);
        if (\App\Models\HppSnapshot::isDateLocked($log->store_id, $request->waste_date)
            || \App\Models\HppSnapshot::isDateLocked($log->store_id, $log->waste_date->format('Y-m-d'))) {
            return back()->withInput()->with('error',
                \App\Models\HppSnapshot::lockMessageFor($log->store_id, $wc->month, $wc->year));
        }

        try {
        DB::transaction(function () use ($request, $log) {
            $storeId = $log->store_id;

            // Ingredient lama yang terdampak
            $oldIngIds = $log->items->pluck('ingredient_id')->unique()->all();

            // 1. Hapus stock ledger lama untuk waste log ini
            \App\Models\StockLedger::where('reference_type', 'WasteLog')
                ->where('reference_id', $log->id)
                ->delete();

            // 2. Hapus items lama
            $log->items()->delete();

            // 3. Restore FIFO untuk ingredient lama supaya saldo stok kembali akurat
            foreach ($oldIngIds as $ingId) {
                FifoService::recalculate($storeId, (int)$ingId);
            }

            // ── Validasi stok (hanya porsi Dus+Pack; pcs/gr tidak potong stok) ──
            // Dilakukan setelah recalculate() di atas, sehingga store_stocks.stock_balance
            // sudah akurat (stok lama sudah dikembalikan).
            // Validasi PER KEMASAN (raw saja; pcs/gr tidak potong stok).
            $allNewItems     = array_merge($request->items ?? [], $request->reworks ?? []);
            $deductionsByPkg = [];
            foreach ($allNewItems as $item) {
                if (empty($item['ingredient_id'])) continue;
                $ingObj = Ingredient::find($item['ingredient_id']);
                if (!$ingObj || !$ingObj->isRaw()) continue; // semi-finished tidak distok
                $pkg       = !empty($item['packaging_id']) ? IngredientPackaging::find($item['packaging_id']) : null;
                $qtyCrate  = (int)($item['qty_crate'] ?? 0);
                $qtyPack   = (int)($item['qty_pack']  ?? 0);
                $qtyBase   = (float)($item['qty_base'] ?? 0);
                $totalBase = $pkg ? $pkg->convertToBase($qtyCrate, $qtyPack, $qtyBase) : $qtyBase;
                if ($totalBase <= 0) continue;
                $stockBase = $pkg ? $pkg->convertToBase($qtyCrate, $qtyPack, 0) : $totalBase;
                if ($stockBase <= 0) continue;
                $k = (int)$item['ingredient_id'] . '-' . ($item['packaging_id'] ?: '0');
                $deductionsByPkg[$k] = ($deductionsByPkg[$k] ?? 0) + $stockBase;
            }
            foreach ($deductionsByPkg as $k => $totalNeeded) {
                [$ingId, $pkgId] = array_pad(explode('-', $k), 2, '0');
                $available = \App\Models\MutationItem::whereHas('mutation', fn($q) =>
                        $q->where('destination_store_id', $storeId)->where('status', 'confirmed'))
                    ->where('ingredient_id', (int)$ingId)
                    ->when((int)$pkgId > 0, fn($q) => $q->where('packaging_id', (int)$pkgId),
                                            fn($q) => $q->whereNull('packaging_id'))
                    ->sum('remaining_qty');
                if ($totalNeeded > $available + 0.001) {
                    $ing = Ingredient::find((int)$ingId);
                    throw new \RuntimeException('STOCK_ERROR: Stok "' . $ing->name . '" pada kemasan ini tidak mencukupi. '
                        . 'Tersedia: ' . number_format($available, 2, ',', '.') . ' ' . $ing->unit_base
                        . ', dibutuhkan: ' . number_format($totalNeeded, 2, ',', '.') . ' ' . $ing->unit_base . '.');
                }
            }

            // 4. Update header
            $log->update([
                'waste_date'        => $request->waste_date,
                'notes'             => $request->notes,
                'total_loss_amount' => 0,
            ]);

            $totalLoss   = 0;
            $newIngIds   = [];

            foreach ($request->items ?? [] as $item) {
                $ingredient = Ingredient::find($item['ingredient_id']);
                abort_if(!$ingredient, 422, 'Bahan tidak ditemukan.');

                $packaging = !empty($item['packaging_id'])
                    ? IngredientPackaging::find($item['packaging_id']) : null;

                $qtyCrate = (int)($item['qty_crate'] ?? 0);
                $qtyPack  = (int)($item['qty_pack']  ?? 0);
                $qtyBase  = (float)($item['qty_base'] ?? 0);

                $totalBase = $packaging
                    ? $packaging->convertToBase($qtyCrate, $qtyPack, $qtyBase)
                    : $qtyBase;

                if ($totalBase <= 0) continue;

                $newIngIds[] = $ingredient->id;

                if ($ingredient->isRaw()) {
                    $price    = FifoService::getCost($storeId, $ingredient->id, $totalBase, $item['packaging_id'] ?? null) / max($totalBase, 0.0001);
                    $subtotal = $totalBase * $price;

                    $log->items()->create([
                        'source_type'          => 'raw',
                        'source_ingredient_id' => $ingredient->id,
                        'source_qty'           => $totalBase,
                        'packaging_id'         => $item['packaging_id'] ?? null,
                        'qty_crate'            => $qtyCrate ?: null,
                        'qty_pack'             => $qtyPack  ?: null,
                        'ingredient_id'        => $ingredient->id,
                        'qty_base'             => $totalBase,
                        'price_per_base'       => $price,
                        'subtotal_loss'        => $subtotal,
                    ]);
                    $stockBase = $packaging ? $packaging->convertToBase($qtyCrate, $qtyPack, 0) : $totalBase;
                    if ($stockBase > 0) {
                        StockLedgerService::record($storeId, $ingredient->id, $request->waste_date, 'waste', -$stockBase, 'WasteLog', $log->id);
                    }
                    $totalLoss += $subtotal;

                } else {
                    $ingredient->loadMissing('compositions');
                    $nominalLoss = 0;

                    foreach ($ingredient->compositions as $comp) {
                        $rawQtyNeeded = $comp->qty_needed_exact * $totalBase;
                        $rawCost      = FifoService::getCost($storeId, $comp->child_id, $rawQtyNeeded);
                        $nominalLoss += $rawCost;
                    }

                    if ($nominalLoss == 0) {
                        $nominalLoss = (float)($item['nominal_loss'] ?? 0);
                    }

                    $pricePerBase = $totalBase > 0 ? $nominalLoss / $totalBase : 0;

                    $log->items()->create([
                        'source_type'          => 'semi_finished',
                        'source_ingredient_id' => $ingredient->id,
                        'source_qty'           => $totalBase,
                        'packaging_id'         => $item['packaging_id'] ?? null,
                        'qty_crate'            => $qtyCrate ?: null,
                        'qty_pack'             => $qtyPack  ?: null,
                        'ingredient_id'        => $ingredient->id,
                        'qty_base'             => $totalBase,
                        'price_per_base'       => $pricePerBase,
                        'subtotal_loss'        => $nominalLoss,
                    ]);
                    // Bahan setengah jadi tidak memotong stok — hanya catat nilai kerugian.
                    $totalLoss += $nominalLoss;
                }
            }

            // ── Rework items (potong stok, tapi kerugian = 0) ─────────
            foreach ($request->reworks ?? [] as $item) {
                $ingredient = Ingredient::find($item['ingredient_id']);
                if (!$ingredient) continue;

                $packaging = !empty($item['packaging_id'])
                    ? IngredientPackaging::find($item['packaging_id']) : null;

                $qtyCrate  = (int)($item['qty_crate'] ?? 0);
                $qtyPack   = (int)($item['qty_pack']  ?? 0);
                $qtyBase   = (float)($item['qty_base'] ?? 0);
                $totalBase = $packaging
                    ? $packaging->convertToBase($qtyCrate, $qtyPack, $qtyBase)
                    : $qtyBase;

                if ($totalBase <= 0) continue;

                $newIngIds[] = $ingredient->id;

                $log->items()->create([
                    'source_type'          => $ingredient->isRaw() ? 'raw' : 'semi_finished',
                    'source_ingredient_id' => $ingredient->id,
                    'source_qty'           => $totalBase,
                    'packaging_id'         => $item['packaging_id'] ?? null,
                    'qty_crate'            => $qtyCrate ?: null,
                    'qty_pack'             => $qtyPack  ?: null,
                    'ingredient_id'        => $ingredient->id,
                    'qty_base'             => $totalBase,
                    'price_per_base'       => 0,
                    'subtotal_loss'        => 0,
                    'is_rework'            => true,
                ]);
                // Hanya bahan baku yang memotong stok (semi-finished tidak distok)
                $stockBase = $ingredient->isRaw()
                    ? ($packaging ? $packaging->convertToBase($qtyCrate, $qtyPack, 0) : $totalBase)
                    : 0;
                if ($stockBase > 0) {
                    StockLedgerService::record($storeId, $ingredient->id, $request->waste_date, 'waste', -$stockBase, 'WasteLog', $log->id);
                }
            }

            $log->update(['total_loss_amount' => $totalLoss]);

            // 5. Recalculate FIFO semua ingredient terdampak (lama + baru)
            foreach (array_unique(array_merge($oldIngIds, $newIngIds)) as $ingId) {
                FifoService::recalculate($storeId, (int)$ingId);
            }
        });
        } catch (\RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'STOCK_ERROR: ')) {
                return back()->withInput()->withErrors(['stock_error' => substr($e->getMessage(), 13)]);
            }
            throw $e;
        }

        return redirect()->route('waste.logs.show', $log)->with('success', 'Waste berhasil diupdate.');
    }

    public function destroy(WasteLog $log)
    {
        // Lock periode oleh opname / snapshot HPP: menghapus waste di periode tertutup
        // akan men-recalc FIFO dan mengubah stok historis yang sudah dibekukan → blokir.
        $wd = $log->waste_date->format('Y-m-d');
        $wc = $log->waste_date;
        if (\App\Models\Opname::isDateLocked($log->store_id, $wd)) {
            return back()->with('error', \App\Models\Opname::lockMessageFor($log->store_id));
        }
        if (\App\Models\HppSnapshot::isDateLocked($log->store_id, $wd)) {
            return back()->with('error',
                \App\Models\HppSnapshot::lockMessageFor($log->store_id, $wc->month, $wc->year));
        }

        DB::transaction(function () use ($log) {
            $storeId = $log->store_id;
            $ingIds  = $log->items->pluck('ingredient_id')->unique()->all();

            // Hapus stock ledger entries untuk waste log ini
            \App\Models\StockLedger::where('reference_type', 'WasteLog')
                ->where('reference_id', $log->id)
                ->delete();

            // Hapus items & log
            $log->items()->delete();
            $log->delete();

            // Recalculate FIFO → restore stok & sync store_stocks
            foreach ($ingIds as $ingId) {
                FifoService::recalculate($storeId, (int)$ingId);
            }
        });

        return redirect()->route('waste.logs.index')->with('success', 'Waste berhasil dihapus.');
    }
}
