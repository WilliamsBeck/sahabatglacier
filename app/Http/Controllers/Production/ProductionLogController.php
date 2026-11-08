<?php
namespace App\Http\Controllers\Production;
use App\Http\Controllers\Controller;
use App\Models\{ProductionLog, ProductionLogItem, Ingredient, Store};
use App\Services\{FifoService, StockLedgerService};
use App\Exports\ArrayExport;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductionLogController extends Controller
{
    public function index(Request $request)
    {
        $storeIds = auth()->user()->accessibleStoreIds();
        $query    = ProductionLog::with(['store','semiFinished'])->whereIn('store_id',$storeIds);
        if ($request->store_id) $query->where('store_id',$request->store_id);
        if ($request->date_from) $query->where('production_date','>=',$request->date_from);
        if ($request->date_to)   $query->where('production_date','<=',$request->date_to);
        $logs   = $query->with('items')->latest('production_date')->paginate(20);
        $stores = auth()->user()->accessibleStores();
        return view('production.index', compact('logs','stores'));
    }

    public function create()
    {
        $stores       = auth()->user()->accessibleStores();
        $semiFinished = Ingredient::with('compositions.child')
            ->where('type','semi_finished')->where('is_active',true)->orderBy('name')->get();
        return view('production.create', compact('stores','semiFinished'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'store_id'                  => 'required|exists:stores,id',
            'production_date'           => 'required|date',
            'notes'                     => 'nullable|string',
            'items'                     => 'required|array|min:1',
            'items.*.semi_finished_id'  => 'required|exists:ingredients,id',
            'items.*.qty_produced'      => 'required|numeric|min:0.001',
        ]);

        $storeId        = $request->store_id;
        $productionDate = $request->production_date;
        $createdCount   = 0;

        DB::transaction(function () use ($request, $storeId, $productionDate, &$createdCount) {
            foreach ($request->items as $item) {
                $ingredient  = Ingredient::with('compositions.child')->findOrFail($item['semi_finished_id']);
                $qtyProduced = (float)$item['qty_produced'];

                $log = ProductionLog::create([
                    'store_id'         => $storeId,
                    'semi_finished_id' => $ingredient->id,
                    'qty_produced'     => $qtyProduced,
                    'production_date'  => $productionDate,
                    'notes'            => $request->notes,
                    'created_by'       => auth()->id(),
                ]);

                // Catat bahan baku yang dikonsumsi + hitung harga FIFO (tanpa potong stok)
                foreach ($ingredient->compositions as $comp) {
                    $qtyNeeded    = $comp->qty_needed * $qtyProduced;
                    $cost         = FifoService::getCost($storeId, $comp->child_id, $qtyNeeded);
                    $pricePerBase = $qtyNeeded > 0 ? $cost / $qtyNeeded : 0;

                    $log->items()->create([
                        'raw_ingredient_id' => $comp->child_id,
                        'qty_consumed'      => $qtyNeeded,
                        'price_per_base'    => $pricePerBase,
                    ]);
                }

                $createdCount++;
            }
        });

        return redirect()->route('production.logs.index')
            ->with('success', "Produksi berhasil dicatat ({$createdCount} bahan).");
    }

    public function show(ProductionLog $log)
    {
        $this->wajibAksesToko((int) $log->store_id);
        $log->load(['store','semiFinished','items.rawIngredient','createdBy']);
        return view('production.show', compact('log'));
    }

    public function edit(ProductionLog $log)
    {
        $this->wajibAksesToko((int) $log->store_id);
        $log->load('items.rawIngredient');
        $stores       = auth()->user()->accessibleStores();
        $semiFinished = Ingredient::with('compositions.child')
            ->where('type','semi_finished')->where('is_active',true)->orderBy('name')->get();
        return view('production.edit', compact('log','stores','semiFinished'));
    }

    public function update(Request $request, ProductionLog $log)
    {
        $this->wajibAksesToko((int) $log->store_id);
        $request->validate([
            'semi_finished_id' => 'required|exists:ingredients,id',
            'production_date'  => 'required|date',
            'qty_produced'     => 'required|numeric|min:0.001',
            'notes'            => 'nullable|string',
        ]);

        DB::transaction(function () use ($request, $log) {
            $ingredient  = Ingredient::with('compositions.child')->findOrFail($request->semi_finished_id);
            $qtyProduced = (float)$request->qty_produced;

            // Hapus items lama, buat ulang
            $log->items()->delete();

            $log->update([
                'semi_finished_id' => $ingredient->id,
                'production_date'  => $request->production_date,
                'qty_produced'     => $qtyProduced,
                'notes'            => $request->notes,
            ]);

            foreach ($ingredient->compositions as $comp) {
                $qtyNeeded    = $comp->qty_needed * $qtyProduced;
                $cost         = FifoService::getCost($log->store_id, $comp->child_id, $qtyNeeded);
                $pricePerBase = $qtyNeeded > 0 ? $cost / $qtyNeeded : 0;

                $log->items()->create([
                    'raw_ingredient_id' => $comp->child_id,
                    'qty_consumed'      => $qtyNeeded,
                    'price_per_base'    => $pricePerBase,
                ]);
            }
        });

        return redirect()->route('production.logs.show', $log)
            ->with('success', 'Data produksi berhasil diupdate.');
    }

    public function destroy(ProductionLog $log)
    {
        $this->wajibAksesToko((int) $log->store_id);
        DB::transaction(function () use ($log) {
            // Bersihkan stock ledger lama jika ada (dari versi sebelumnya yang masih potong stok)
            $ingIds = $log->items->pluck('raw_ingredient_id')->push($log->semi_finished_id)->unique()->filter()->all();

            \App\Models\StockLedger::where('reference_type', 'ProductionLog')
                ->where('reference_id', $log->id)
                ->delete();

            $log->items()->delete();
            $log->delete();

            // Recalculate FIFO untuk bahan yang pernah terdampak (jaga-jaga data lama)
            foreach ($ingIds as $ingId) {
                FifoService::recalculate($log->store_id, (int)$ingId);
            }
        });

        return redirect()->route('production.logs.index')
            ->with('success', 'Data produksi berhasil dihapus.');
    }

    public function export(Request $request)
    {
        $storeIds = auth()->user()->accessibleStoreIds();
        $query    = ProductionLog::with(['store','semiFinished','items.rawIngredient'])
            ->whereIn('store_id', $storeIds);
        if ($request->store_id)  $query->where('store_id', $request->store_id);
        if ($request->date_from) $query->where('production_date', '>=', $request->date_from);
        if ($request->date_to)   $query->where('production_date', '<=', $request->date_to);
        $logs = $query->orderBy('production_date')->get();

        $data = [['Toko', 'Tanggal', 'Produk', 'Qty Diproduksi', 'Satuan', 'Bahan Baku', 'Qty Bahan']];
        foreach ($logs as $log) {
            if ($log->items->isEmpty()) {
                $data[] = [$log->store->name, $log->production_date->format('d/m/Y'),
                    $log->semiFinished?->name ?? '-', $log->qty_produced,
                    $log->semiFinished?->unit_base ?? '-', '-', '-'];
            } else {
                foreach ($log->items as $k => $item) {
                    $data[] = [
                        $k === 0 ? $log->store->name : '',
                        $k === 0 ? $log->production_date->format('d/m/Y') : '',
                        $k === 0 ? ($log->semiFinished?->name ?? '-') : '',
                        $k === 0 ? $log->qty_produced : '',
                        $k === 0 ? ($log->semiFinished?->unit_base ?? '-') : '',
                        $item->rawIngredient?->name ?? '-',
                        $item->qty_consumed,
                    ];
                }
            }
        }

        $suffix = ($request->date_from && $request->date_to)
            ? "_{$request->date_from}_{$request->date_to}"
            : '_' . now()->format('Y-m');

        return Excel::download(new ArrayExport($data), "produksi{$suffix}.xlsx");
    }
}
