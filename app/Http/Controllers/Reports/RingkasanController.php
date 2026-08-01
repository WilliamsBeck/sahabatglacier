<?php
namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\{MonthlyRevenue, WasteLog, WasteLogItem, ProductionLog, ProductionLogItem};
use App\Exports\ArrayExport;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Http\Request;
use Carbon\Carbon;

class RingkasanController extends Controller
{
    public function index(Request $request)
    {
        $stores     = auth()->user()->accessibleStores();
        $month      = (int)($request->month ?? now()->month);
        $year       = (int)($request->year  ?? now()->year);
        $periodType = $request->period_type ?? 'end_month';

        $dateFrom = Carbon::create($year, $month, 1)->toDateString();
        $dateTo   = $periodType === 'mid_month'
            ? Carbon::create($year, $month, 15)->toDateString()
            : Carbon::create($year, $month)->endOfMonth()->toDateString();

        $storeIds = $stores->pluck('id')->all();

        $rows = $this->computeRows($stores, $month, $year, $periodType, $dateFrom, $dateTo);

        // Trend 6 bulan (chart) - TOTAL OMSET semua toko.
        // total_revenue sudah berisi hasil akhir: Omset Bruto + Selisih TikTok.
        $trendMonths = collect();
        for ($i = 5; $i >= 0; $i--) {
            $c = Carbon::create($year, $month, 1)->subMonths($i);
            $omset = MonthlyRevenue::whereIn('store_id', $storeIds)
                ->where('month', $c->month)->where('year', $c->year)
                ->where('period_type', $periodType)->sum('total_revenue');
            $trendMonths->push((object)[
                'label' => $c->isoFormat('MMM YY'),
                'omset' => (float)$omset,
            ]);
        }

        return view('reports.ringkasan', compact(
            'stores', 'month', 'year', 'periodType', 'rows', 'trendMonths'
        ));
    }

    // Hitung ringkasan per toko (dipakai index & export)
    private function computeRows($stores, int $month, int $year, string $periodType, string $dateFrom, string $dateTo)
    {
        $hppCtrl = new \App\Http\Controllers\Sales\HppController();
        $ref     = new \ReflectionMethod($hppCtrl, 'calcHppForStore');
        $ref->setAccessible(true);

        return $stores->map(function ($store) use ($ref, $hppCtrl, $month, $year, $periodType, $dateFrom, $dateTo) {
            $hpp = $ref->invoke($hppCtrl, $store->id, $month, $year, $periodType);

            $totalWaste = WasteLogItem::whereHas('wasteLog', fn($q) =>
                $q->where('store_id', $store->id)
                  ->whereBetween('waste_date', [$dateFrom, $dateTo])
            )->sum('subtotal_loss');

            $totalProdCost = ProductionLogItem::whereHas('productionLog', fn($q) =>
                $q->where('store_id', $store->id)
                  ->whereBetween('production_date', [$dateFrom, $dateTo])
            )->get()->sum(fn($i) => $i->qty_consumed * $i->price_per_base);

            $prodBatch = ProductionLog::where('store_id', $store->id)
                ->whereBetween('production_date', [$dateFrom, $dateTo])
                ->count();

            return (object)[
                'store'          => $store,
                'omset'          => $hpp ? $hpp['summary']->omset : null,
                'hpp_ideal'      => $hpp ? $hpp['summary']->hpp_ideal : null,
                'pct_hpp_ideal'  => $hpp ? $hpp['summary']->pct_hpp_ideal : null,
                'hpp_aktual'     => $hpp ? $hpp['summary']->hpp_aktual : null,
                'pct_hpp_aktual' => $hpp ? $hpp['summary']->pct_hpp_aktual : null,
                'margin_ideal'   => $hpp ? $hpp['summary']->margin_ideal : null,
                'total_waste'    => (float)$totalWaste,
                'prod_cost'      => (float)$totalProdCost,
                'prod_batch'     => $prodBatch,
                'has_data'       => $hpp !== null,
            ];
        });
    }

    // ── Export Excel ringkasan per toko ──────────────────────────────────────
    public function export(Request $request)
    {
        $stores     = auth()->user()->accessibleStores();
        $month      = (int)($request->month ?? now()->month);
        $year       = (int)($request->year  ?? now()->year);
        $periodType = $request->period_type ?? 'end_month';

        $dateFrom = Carbon::create($year, $month, 1)->toDateString();
        $dateTo   = $periodType === 'mid_month'
            ? Carbon::create($year, $month, 15)->toDateString()
            : Carbon::create($year, $month)->endOfMonth()->toDateString();

        $rows = $this->computeRows($stores, $month, $year, $periodType, $dateFrom, $dateTo);

        $fmtPct = fn($v) => $v !== null ? number_format($v, 1, ',', '.') . '%' : '-';

        $data = [[
            'Toko', 'Omset', 'HPP Ideal', '% HPP Ideal', 'HPP Aktual',
            '% HPP Aktual', 'Waste', 'Biaya Produksi', 'Margin',
        ]];

        foreach ($rows as $r) {
            $data[] = [
                $r->store->name,
                (float) ($r->omset ?? 0),
                (float) ($r->hpp_ideal ?? 0),
                $fmtPct($r->pct_hpp_ideal),
                (float) ($r->hpp_aktual ?? 0),
                $fmtPct($r->pct_hpp_aktual),
                (float) $r->total_waste,
                (float) $r->prod_cost,
                $fmtPct($r->margin_ideal),
            ];
        }

        // Baris TOTAL (persentase dihitung dari total — rata-rata tertimbang)
        $totalOmset     = $rows->whereNotNull('omset')->sum('omset');
        $totalHppIdeal  = $rows->whereNotNull('hpp_ideal')->sum('hpp_ideal');
        $totalHppAktual = $rows->whereNotNull('hpp_aktual')->sum('hpp_aktual');
        $totalWaste     = $rows->sum('total_waste');
        $totalProd      = $rows->sum('prod_cost');
        $pctI = $totalOmset > 0 ? $totalHppIdeal  / $totalOmset * 100 : null;
        $pctA = $totalOmset > 0 ? $totalHppAktual / $totalOmset * 100 : null;
        $marg = $totalOmset > 0 ? (1 - $totalHppIdeal / $totalOmset) * 100 : null;

        $data[] = [
            'TOTAL', $totalOmset, $totalHppIdeal, $fmtPct($pctI),
            $totalHppAktual, $fmtPct($pctA), $totalWaste, $totalProd, $fmtPct($marg),
        ];

        $bulan    = Carbon::create($year, $month)->isoFormat('MMMM-Y');
        $periode  = $periodType === 'mid_month' ? 'TengahBulan' : 'AkhirBulan';
        $filename = "Ringkasan-Bisnis_{$bulan}_{$periode}.xlsx";

        return Excel::download(new ArrayExport($data), $filename);
    }
}
