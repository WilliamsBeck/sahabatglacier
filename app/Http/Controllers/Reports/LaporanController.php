<?php
namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\{MonthlySale, MonthlyRevenue, WasteLog, WasteLogItem, ProductionLog, ProductionLogItem, Mutation, MutationItem, Store, Menu, MenuCategory};
use Illuminate\Http\Request;
use Carbon\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\ArrayExport;

class LaporanController extends Controller
{
    // ══════════════════════════════════════════════════════════════════════
    // 0. INDEX / HUB
    // ══════════════════════════════════════════════════════════════════════
    public function index()
    {
        return view('reports.laporan.index');
    }

    // ── Helper: validasi store access ──────────────────────────────────────
    private function resolveStore(Request $request): ?int
    {
        $ids     = auth()->user()->accessibleStoreIds();
        $storeId = $request->store_id ? (int)$request->store_id : ($ids[0] ?? null);
        return ($storeId && in_array($storeId, $ids)) ? $storeId : null;
    }

    private function mLabel(int $m, int $y): string
    {
        return \Carbon\Carbon::create($y, $m)->isoFormat('MMMM Y');
    }

    // ══════════════════════════════════════════════════════════════════════
    // 1. MENU TERJUAL
    // ══════════════════════════════════════════════════════════════════════
    public function menuTerjual(Request $request)
    {
        $stores  = auth()->user()->accessibleStores();
        $storeId = $this->resolveStore($request);
        $month   = (int)($request->month ?? now()->month);
        $year    = (int)($request->year  ?? now()->year);
        $periodType = $request->period_type ?? 'end_month';

        $rows = collect();
        $totalSold = 0; $totalRevenue = 0;

        if ($storeId) {
            $rows = MonthlySale::with(['menu.menuCategory'])
                ->where('store_id', $storeId)
                ->where('month', $month)->where('year', $year)
                ->where('period_type', $periodType)
                ->get()
                ->sortByDesc('total_sold')->values();

            $totalSold    = $rows->sum('total_sold');
            // Omset diambil dari MonthlyRevenue (sama seperti halaman penjualan/HPP),
            // bukan dari kolom per-menu total_revenue yang belum terisi.
            $totalRevenue = MonthlyRevenue::where('store_id', $storeId)
                ->where('month', $month)->where('year', $year)
                ->where('period_type', $periodType)
                ->value('total_revenue') ?? 0;
        }

        // Grouping by category for chart
        $byCategory = $rows->groupBy(fn($r) => $r->menu?->menuCategory?->name ?? 'Lainnya')
            ->map(fn($g) => $g->sum('total_sold'));

        return view('reports.laporan.menu-terjual', compact(
            'stores', 'storeId', 'month', 'year', 'periodType',
            'rows', 'totalSold', 'totalRevenue', 'byCategory'
        ));
    }

    public function exportMenuTerjual(Request $request)
    {
        $storeId    = $this->resolveStore($request);
        $month      = (int)($request->month ?? now()->month);
        $year       = (int)($request->year  ?? now()->year);
        $periodType = $request->period_type ?? 'end_month';
        if (!$storeId) abort(403);

        $store = Store::find($storeId);
        $rows  = MonthlySale::with(['menu.menuCategory'])
            ->where('store_id', $storeId)
            ->where('month', $month)->where('year', $year)
            ->where('period_type', $periodType)
            ->get()->sortByDesc('total_sold');

        $data = [['Toko', 'Periode', 'Kategori', 'Menu', 'Qty Terjual', 'Total Pendapatan']];
        $label = $this->mLabel($month, $year);
        foreach ($rows as $r) {
            $data[] = [
                $store->name, $label,
                $r->menu?->menuCategory?->name ?? '-',
                $r->menu?->name ?? '-',
                $r->total_sold,
                $r->total_revenue,
            ];
        }
        $data[] = ['', '', '', 'TOTAL', $rows->sum('total_sold'), $rows->sum('total_revenue')];

        return Excel::download(new ArrayExport($data), "menu-terjual_{$store->name}_{$month}-{$year}.xlsx");
    }

    // ══════════════════════════════════════════════════════════════════════
    // 2. DATA HPP
    // ══════════════════════════════════════════════════════════════════════
    public function hpp(Request $request)
    {
        $stores     = auth()->user()->accessibleStores();
        $storeIds   = auth()->user()->accessibleStoreIds();
        $month      = (int)($request->month ?? now()->month);
        $year       = (int)($request->year  ?? now()->year);
        $periodType = $request->period_type ?? 'end_month';

        // Ambil summary HPP untuk semua toko sekaligus
        $hppCtrl = new \App\Http\Controllers\Sales\HppController();
        $ref     = new \ReflectionMethod($hppCtrl, 'calcHppForStore');
        $ref->setAccessible(true);

        $rows = $stores->map(function ($store) use ($ref, $hppCtrl, $month, $year, $periodType) {
            $result = $ref->invoke($hppCtrl, $store->id, $month, $year, $periodType);
            return (object)[
                'store'          => $store,
                'omset'          => $result ? $result['summary']->omset : null,
                'hpp_ideal'      => $result ? $result['summary']->hpp_ideal : null,
                'hpp_aktual'     => $result ? $result['summary']->hpp_aktual : null,
                'pct_hpp_ideal'  => $result ? $result['summary']->pct_hpp_ideal : null,
                'pct_hpp_aktual' => $result ? $result['summary']->pct_hpp_aktual : null,
                'margin_ideal'   => $result ? $result['summary']->margin_ideal : null,
                'has_data'       => $result !== null,
            ];
        })->filter(fn($r) => $r->has_data)->values();

        return view('reports.laporan.hpp', compact(
            'stores', 'month', 'year', 'periodType', 'rows'
        ));
    }

    public function exportHpp(Request $request)
    {
        $stores     = auth()->user()->accessibleStores();
        $month      = (int)($request->month ?? now()->month);
        $year       = (int)($request->year  ?? now()->year);
        $periodType = $request->period_type ?? 'end_month';

        $hppCtrl = new \App\Http\Controllers\Sales\HppController();
        $ref     = new \ReflectionMethod($hppCtrl, 'calcHppForStore');
        $ref->setAccessible(true);

        $data  = [['Toko', 'Periode', 'Omset', 'HPP Ideal', '% HPP Ideal', 'HPP Aktual', '% HPP Aktual', 'Margin Ideal']];
        $label = $this->mLabel($month, $year);

        foreach ($stores as $store) {
            $result = $ref->invoke($hppCtrl, $store->id, $month, $year, $periodType);
            if (!$result) continue;
            $s = $result['summary'];
            $data[] = [
                $store->name, $label,
                $s->omset,
                $s->hpp_ideal,
                $s->pct_hpp_ideal ? number_format($s->pct_hpp_ideal, 2, ',', '.') . '%' : '-',
                $s->hpp_aktual ?? '-',
                $s->pct_hpp_aktual ? number_format($s->pct_hpp_aktual, 2, ',', '.') . '%' : '-',
                $s->margin_ideal ? number_format($s->margin_ideal, 2, ',', '.') . '%' : '-',
            ];
        }

        return Excel::download(new ArrayExport($data), "hpp_{$month}-{$year}.xlsx");
    }

    // ══════════════════════════════════════════════════════════════════════
    // 3. DATA WASTE
    // ══════════════════════════════════════════════════════════════════════
    public function waste(Request $request)
    {
        $stores     = auth()->user()->accessibleStores();
        $storeId    = $this->resolveStore($request);
        $dateFrom   = $request->date_from ?? now()->startOfMonth()->toDateString();
        $dateTo     = $request->date_to   ?? now()->toDateString();

        $rows = collect(); $grandTotal = 0;

        if ($storeId) {
            $rows = WasteLogItem::with(['ingredient.packagings', 'wasteLog'])
                ->whereHas('wasteLog', fn($q) =>
                    $q->where('store_id', $storeId)
                      ->whereBetween('waste_date', [$dateFrom, $dateTo])
                )->get()
                ->sortByDesc(fn($r) => $r->wasteLog->waste_date)
                ->values();

            $grandTotal = $rows->sum('subtotal_loss');
        }

        // Pisahkan kerugian waste bahan baku (raw) vs setengah jadi (semi_finished)
        $rawTotal  = $rows->where('source_type', 'raw')->sum('subtotal_loss');
        $semiTotal = $rows->where('source_type', 'semi_finished')->sum('subtotal_loss');

        return view('reports.laporan.waste', compact(
            'stores', 'storeId', 'dateFrom', 'dateTo', 'rows', 'grandTotal', 'rawTotal', 'semiTotal'
        ));
    }

    public function exportWaste(Request $request)
    {
        $storeId  = $this->resolveStore($request);
        $dateFrom = $request->date_from ?? now()->startOfMonth()->toDateString();
        $dateTo   = $request->date_to   ?? now()->toDateString();
        if (!$storeId) abort(403);

        $store = Store::find($storeId);
        $rows  = WasteLogItem::with(['ingredient', 'wasteLog'])
            ->whereHas('wasteLog', fn($q) =>
                $q->where('store_id', $storeId)
                  ->whereBetween('waste_date', [$dateFrom, $dateTo])
            )->get()->sortBy(fn($r) => $r->wasteLog->waste_date);

        $data = [['Toko', 'Tanggal', 'Bahan', 'Satuan', 'Qty Base', 'Harga/Base', 'Kerugian', 'Catatan']];
        foreach ($rows as $r) {
            $data[] = [
                $store->name,
                Carbon::parse($r->wasteLog->waste_date)->format('d/m/Y'),
                $r->ingredient?->name ?? '-',
                $r->ingredient?->unit_base ?? '-',
                $r->qty_base,
                $r->price_per_base,
                $r->subtotal_loss,
                $r->wasteLog->notes ?? '',
            ];
        }
        $data[] = ['', '', '', '', '', 'TOTAL', $rows->sum('subtotal_loss'), ''];

        return Excel::download(new ArrayExport($data), "waste_{$store->name}_{$dateFrom}_{$dateTo}.xlsx");
    }

    // ══════════════════════════════════════════════════════════════════════
    // 4. DATA PRODUKSI
    // ══════════════════════════════════════════════════════════════════════
    public function produksi(Request $request)
    {
        $stores   = auth()->user()->accessibleStores();
        $storeId  = $this->resolveStore($request);
        $dateFrom = $request->date_from ?? now()->startOfMonth()->toDateString();
        $dateTo   = $request->date_to   ?? now()->toDateString();

        $rows = collect(); $grandTotal = 0; $totalBatch = 0;

        if ($storeId) {
            $logs = ProductionLog::with(['semiFinished', 'items.rawIngredient'])
                ->where('store_id', $storeId)
                ->whereBetween('production_date', [$dateFrom, $dateTo])
                ->orderBy('production_date')
                ->get();

            $rows = $logs->map(function ($log) {
                $cost = $log->items->sum(fn($i) => $i->qty_consumed * $i->price_per_base);
                return (object)[
                    'log'      => $log,
                    'product'  => $log->semiFinished,
                    'qty'      => $log->qty_produced,
                    'cost'     => $cost,
                    'items'    => $log->items,
                ];
            });

            $grandTotal = $rows->sum('cost');
            $totalBatch = $rows->count();
        }

        return view('reports.laporan.produksi', compact(
            'stores', 'storeId', 'dateFrom', 'dateTo', 'rows', 'grandTotal', 'totalBatch'
        ));
    }

    public function exportProduksi(Request $request)
    {
        $storeId  = $this->resolveStore($request);
        $dateFrom = $request->date_from ?? now()->startOfMonth()->toDateString();
        $dateTo   = $request->date_to   ?? now()->toDateString();
        if (!$storeId) abort(403);

        $store = Store::find($storeId);
        $logs  = ProductionLog::with(['semiFinished', 'items.rawIngredient'])
            ->where('store_id', $storeId)
            ->whereBetween('production_date', [$dateFrom, $dateTo])
            ->orderBy('production_date')->get();

        $data = [['Toko', 'Tanggal', 'Produk', 'Qty Diproduksi', 'Satuan', 'Bahan Dikonsumsi', 'Qty Bahan', 'Harga/Base', 'Biaya']];
        foreach ($logs as $log) {
            $cost = $log->items->sum(fn($i) => $i->qty_consumed * $i->price_per_base);
            if ($log->items->isEmpty()) {
                $data[] = [
                    $store->name,
                    $log->production_date->format('d/m/Y'),
                    $log->semiFinished?->name ?? '-',
                    $log->qty_produced,
                    $log->semiFinished?->unit_base ?? '-',
                    '-', '-', '-', $cost,
                ];
            } else {
                foreach ($log->items as $i => $item) {
                    $data[] = [
                        $i === 0 ? $store->name : '',
                        $i === 0 ? $log->production_date->format('d/m/Y') : '',
                        $i === 0 ? ($log->semiFinished?->name ?? '-') : '',
                        $i === 0 ? $log->qty_produced : '',
                        $i === 0 ? ($log->semiFinished?->unit_base ?? '-') : '',
                        $item->rawIngredient?->name ?? '-',
                        $item->qty_consumed,
                        $item->price_per_base,
                        $item->qty_consumed * $item->price_per_base,
                    ];
                }
            }
        }
        $data[] = ['', '', '', '', '', '', '', 'TOTAL', $logs->flatMap->items->sum(fn($i) => $i->qty_consumed * $i->price_per_base)];

        return Excel::download(new ArrayExport($data), "produksi_{$store->name}_{$dateFrom}_{$dateTo}.xlsx");
    }

    // ══════════════════════════════════════════════════════════════════════
    // 5. LAPORAN MUTASI STOK
    // ══════════════════════════════════════════════════════════════════════
    public function mutasiStok(Request $request)
    {
        $stores   = auth()->user()->accessibleStores();
        $storeId  = $this->resolveStore($request);
        $dateFrom = $request->date_from ?? now()->startOfMonth()->toDateString();
        $dateTo   = $request->date_to   ?? now()->toDateString();
        $tipe     = $request->tipe ?? 'semua'; // semua | pi | eksternal | zhisheng | supplier

        $typeMap = [
            'pi'        => ['sale_internal'],
            'eksternal' => ['sale_external'],
            'penjualan_eksternal' => ['sale_external_out'],
            'zhisheng'  => ['purchase_zhisheng'],
            'supplier'  => ['purchase_supplier'],
        ];

        $rows = collect(); $grandTotal = 0;

        if ($storeId) {
            $query = Mutation::with(['supplier', 'items.ingredient', 'items.packaging', 'sourceStore', 'destinationStore'])
                ->where(fn($q) =>
                    $q->where('destination_store_id', $storeId)
                      ->orWhere('source_store_id', $storeId)
                )
                ->where('status', 'confirmed')
                ->whereRaw('COALESCE(delivery_date, transaction_date) BETWEEN ? AND ?', [$dateFrom, $dateTo]);

            if ($tipe === 'internal_in') {
                $query->where('type', 'sale_internal')->where('destination_store_id', $storeId);
            } elseif ($tipe === 'internal_out') {
                $query->where('type', 'sale_internal')->where('source_store_id', $storeId);
            } elseif ($tipe !== 'semua' && isset($typeMap[$tipe])) {
                $query->whereIn('type', $typeMap[$tipe]);
            } else {
                $query->whereIn('type', ['purchase_zhisheng', 'purchase_supplier', 'sale_internal', 'sale_external', 'sale_external_out', 'opening_stock']);
            }

            $rows       = $query->orderBy('transaction_date')->get();
            $grandTotal = $rows->flatMap->items->sum('cost_subtotal');
        }

        return view('reports.laporan.mutasi-stok', compact(
            'stores', 'storeId', 'dateFrom', 'dateTo', 'tipe', 'rows', 'grandTotal'
        ));
    }

    public function exportMutasiStok(Request $request)
    {
        $storeId  = $this->resolveStore($request);
        $dateFrom = $request->date_from ?? now()->startOfMonth()->toDateString();
        $dateTo   = $request->date_to   ?? now()->toDateString();
        $tipe     = $request->tipe ?? 'semua';
        if (!$storeId) abort(403);

        $store   = Store::find($storeId);
        $typeMap = [
            'pi'        => ['sale_internal'],
            'eksternal' => ['sale_external'],
            'penjualan_eksternal' => ['sale_external_out'],
            'zhisheng'  => ['purchase_zhisheng'],
            'supplier'  => ['purchase_supplier'],
        ];

        $query = Mutation::with(['supplier', 'items.ingredient', 'destinationStore'])
            ->where(fn($q) =>
                $q->where('destination_store_id', $storeId)
                  ->orWhere('source_store_id', $storeId)
            )
            ->where('status', 'confirmed')
            ->whereRaw('COALESCE(delivery_date, transaction_date) BETWEEN ? AND ?', [$dateFrom, $dateTo]);

        if ($tipe === 'internal_in') {
            $query->where('type', 'sale_internal')->where('destination_store_id', $storeId);
        } elseif ($tipe === 'internal_out') {
            $query->where('type', 'sale_internal')->where('source_store_id', $storeId);
        } elseif ($tipe !== 'semua' && isset($typeMap[$tipe])) {
            $query->whereIn('type', $typeMap[$tipe]);
        }

        $mutations = $query->orderBy('transaction_date')->get();

        $data = [['Tgl Transaksi', 'Tipe', 'No Ref', 'No Invoice', 'Supplier/Sumber', 'Toko Tujuan', 'Bahan', 'Qty Base', 'Harga/Base', 'Subtotal']];
        foreach ($mutations as $m) {
            foreach ($m->items as $i => $item) {
                $data[] = [
                    $i === 0 ? $m->transaction_date->format('d/m/Y') : '',
                    $i === 0 ? $m->type_label : '',
                    $i === 0 ? ($m->reference_no ?? '-') : '',
                    $i === 0 ? ($m->invoice_no ?? '-') : '',
                    $i === 0 ? ($m->supplier?->name ?? $m->sourceStore?->name ?? '-') : '',
                    $i === 0 ? ($m->destinationStore?->name ?? '-') : '',
                    $item->ingredient?->name ?? '-',
                    $item->total_in_base,
                    $item->price_per_base,
                    $item->cost_subtotal,
                ];
            }
        }
        $data[] = ['', '', '', '', '', '', '', '', 'TOTAL', $mutations->flatMap->items->sum('cost_subtotal')];

        return Excel::download(new ArrayExport($data), "mutasi_{$tipe}_{$store->name}_{$dateFrom}_{$dateTo}.xlsx");
    }
}
