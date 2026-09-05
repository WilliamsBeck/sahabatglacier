<?php
namespace App\Http\Controllers;

use App\Models\{Ingredient, IngredientCategory, IngredientPackaging, Opname, OpnameItem, Store};
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderPlanningController extends Controller
{
    public function index(Request $request)
    {
        $stores   = auth()->user()->accessibleStores();
        $storeIds = auth()->user()->accessibleStoreIds();
        // startOfMonth dulu agar tidak overflow saat hari ini tgl 29–31
        // (mis. 31 Mei - 1 bulan = "31 April" → meleset ke 1 Mei).
        $defaultRef = now()->startOfMonth()->subMonth();

        // Daftar opname approved per toko (untuk dropdown sumber stok)
        $opnamesByStore = Opname::whereIn('store_id', $storeIds)
            ->where('status', 'approved')
            ->orderByDesc('opname_date')
            ->get(['id', 'store_id', 'opname_date', 'period_month', 'period_year', 'period_type'])
            ->groupBy('store_id');

        $storeConfigs = $stores->mapWithKeys(fn($s) => [$s->id => [
            'lead_time_days'   => $s->lead_time_days   ? (int)$s->lead_time_days   : null,
            'order_cycle_days' => $s->order_cycle_days ? (int)$s->order_cycle_days : null,
            'dos_window_days'  => $s->dosWindowDays(),
        ]]);

        // Belum pilih toko ATAU form belum lengkap → tampilkan form kosong
        if (!$request->filled('store_id') || !$request->filled('ref_date') || !$request->filled('next_order_date')) {
            return view('order-planning.index', [
                'stores'          => $stores,
                'storeConfigs'    => $storeConfigs,
                'opnamesByStore'  => $opnamesByStore,
                'tableData'       => false,
                'defaultMonth'    => $defaultRef->month,
                'defaultYear'     => $defaultRef->year,
            ]);
        }

        $request->validate([
            'store_id'        => 'required|exists:stores,id',
            'order_month'     => 'required|integer|between:1,12',
            'order_year'      => 'required|integer|min:2020',
            'ref_date'        => 'required|date',
            'next_order_date' => 'required|date|after_or_equal:ref_date',
            'ref_month'       => 'required|integer|between:1,12',
            'ref_year'        => 'required|integer|min:2020',
            'buffer_pct'      => 'nullable|numeric|min:0|max:100',
            'stock_source'    => 'nullable|in:fifo,opname',
            'opname_id'       => 'nullable|exists:opnames,id',
        ]);

        // Derive: hari kebutuhan = arrival_next - ref_date
        // delivery_date  = ref_date (titik awal stok dipakai)
        // coverage_end   = next_order_date + lead_time (= tgl tiba pesanan berikutnya)
        $store     = Store::find($request->store_id);
        $leadTime  = $store?->leadTimeDays() ?? 0;
        $nextOrder = Carbon::parse($request->next_order_date);
        $arrival   = $nextOrder->copy()->addDays($leadTime);

        $request->merge([
            'delivery_date' => $request->ref_date,
            'coverage_end'  => $arrival->toDateString(),
        ]);

        $storeId = (int)$request->store_id;
        abort_unless(in_array($storeId, $storeIds), 403);

        $data = $this->buildTableData($request, $storeId);

        return view('order-planning.index', array_merge($data, [
            'stores'         => $stores,
            'storeConfigs'   => $storeConfigs,
            'opnamesByStore' => $opnamesByStore,
            'defaultMonth'   => $data['refMonth'],
            'defaultYear'    => $data['refYear'],
        ]));
    }

    // ── Export sebagai .xls ───────────────────────────────────────────────────
    public function export(Request $request)
    {
        $storeId = (int)$request->store_id;
        abort_unless(in_array($storeId, auth()->user()->accessibleStoreIds()), 403);

        // Derive: hari kebutuhan = arrival_next - ref_date
        if ($request->filled('ref_date') && $request->filled('next_order_date')) {
            $store     = Store::find($storeId);
            $leadTime  = $store?->leadTimeDays() ?? 0;
            $arrival   = Carbon::parse($request->next_order_date)->addDays($leadTime);
            $request->merge([
                'delivery_date' => $request->ref_date,
                'coverage_end'  => $arrival->toDateString(),
            ]);
        }

        $data = $this->buildTableData($request, $storeId);

        if (empty($data['tableData'])) {
            return back()->with('error', 'Tidak ada data untuk diekspor.');
        }

        $filename = 'rencana-order-' . $data['store']->name . '-' . now()->format('Ymd') . '.xls';

        return response()
            ->view('order-planning.export', $data)
            ->header('Content-Type', 'application/vnd.ms-excel; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    // ── Core kalkulasi ────────────────────────────────────────────────────────
    private function buildTableData(Request $request, int $storeId): array
    {
        $store        = Store::find($storeId);
        $orderDate    = $request->filled('order_date') ? Carbon::parse($request->order_date) : null;
        $deliveryDate = Carbon::parse($request->delivery_date);
        $coverageEnd  = Carbon::parse($request->coverage_end);
        $refMonth     = (int)$request->ref_month;
        $refYear      = (int)$request->ref_year;
        $bufferPct    = (float)($request->buffer_pct ?? 0);
        $stockSource  = $request->input('stock_source', 'fifo');
        $opnameId     = $request->filled('opname_id') ? (int)$request->opname_id : null;
        $daysToCover  = $deliveryDate->diffInDays($coverageEnd);

        // Lead time (informasi saja, tidak mempengaruhi kalkulasi)
        $leadTimeDays = $orderDate ? $orderDate->diffInDays($deliveryDate) : null;

        // ── Sumber stok saat ini ──────────────────────────────────────────────
        // Disimpan PER (bahan × kemasan). Satu bahan bisa punya beberapa kemasan
        // dengan isi dus BERBEDA (mis. Big Bag @9 pack dan Big Bag @25 pack).
        // Kalau saldonya dijumlahkan dulu jadi satu angka base lalu dibagi ukuran
        // dus SALAH SATU kemasan, hasil "sisa stok (dus)" jadi meleset — 3 pack
        // dari kemasan @9 (= 0,33 dus) terbaca 3/25 = 0,12 dus.
        $selectedOpname = null;
        if ($stockSource === 'opname' && $opnameId) {
            $selectedOpname = Opname::find($opnameId);
            // HANYA Dus + Pack utuh — eceran pcs/gr TIDAK dihitung sebagai stok.
            // Aturan yang sama dipakai saat opname di-approve (OpnameController:
            // "Simpan HANYA Dus + Pack utuh ke FIFO"), di stok awal Pencatatan
            // Harian, dan di Saldo Stok. Dulu di sini dipakai physical_qty yang
            // ikut menghitung eceran, sehingga 3 pack + 466 pcs terbaca 0,31 dus
            // padahal di modul lain barang itu bernilai 3/25 = 0,12 dus.
            $stockByPkg = [];
            foreach (OpnameItem::with('packaging')->where('opname_id', $opnameId)->get() as $it) {
                $pk  = $it->packaging;
                $ptb = $pk ? (float)$pk->pack_to_base : 0;
                $ctb = ($pk && $pk->crate_to_pack && $ptb) ? (float)$pk->crate_to_pack * $ptb : 0;
                $qty = ($ctb > 0 ? (int)($it->physical_crate ?? 0) * $ctb : 0)
                     + ($ptb > 0 ? (int)($it->physical_pack  ?? 0) * $ptb : 0);
                // Bahan tanpa kemasan tidak punya rincian dus/pack → pakai apa adanya.
                if ($qty <= 0 && !$pk) $qty = (float)$it->physical_qty;
                $k = $it->ingredient_id . '-' . ($it->packaging_id ?: 0);
                $stockByPkg[$k] = ($stockByPkg[$k] ?? 0) + $qty;
            }
        } else {
            // Default: FIFO — sisa batch yang masih ada, dipecah per kemasan.
            // Totalnya identik dengan store_stocks.stock_balance yang dipakai
            // sebelumnya; yang berubah hanya rinciannya jadi per kemasan.
            $stockSource = 'fifo';
            $stockByPkg  = DB::table('mutation_items as mi')
                ->join('mutations as m', 'm.id', '=', 'mi.mutation_id')
                ->where('m.destination_store_id', $storeId)
                ->where('m.status', 'confirmed')
                ->where('mi.remaining_qty', '>', 0)
                ->selectRaw('mi.ingredient_id, mi.packaging_id, SUM(mi.remaining_qty) t')
                ->groupBy('mi.ingredient_id', 'mi.packaging_id')->get()
                ->mapWithKeys(fn($r) => [$r->ingredient_id . '-' . ($r->packaging_id ?: 0) => (float)$r->t])
                ->all();
        }

        // ── Referensi konsumsi ────────────────────────────────────────────────
        $refStart  = Carbon::create($refYear, $refMonth, 1)->toDateString();
        $refEnd    = Carbon::create($refYear, $refMonth, 1)->endOfMonth()->toDateString();
        $daysInRef = Carbon::create($refYear, $refMonth, 1)->daysInMonth;

        $usageSource = 'hpp'; // sumber TUNGGAL: HPP Aktual (pencatatan harian tidak dipakai)

        // ── Konsumsi acuan = HPP AKTUAL ───────────────────────────────────────
        // konsumsi_base = SO Awal + pembelian − transfer keluar − SO Akhir.
        // Identik dengan HppController (HPP Aktual). WAJIB ada SO Awal (opname akhir
        // bulan lalu) DAN SO Akhir (opname akhir bulan referensi) yang sudah approved.
        $prevPeriod    = Carbon::create($refYear, $refMonth, 1)->subMonth();
        $openingOpname = Opname::where('store_id', $storeId)
            ->where('period_month', $prevPeriod->month)
            ->where('period_year',  $prevPeriod->year)
            ->where('period_type',  'end_month')
            ->where('status',       'approved')
            ->first();
        $closingOpname = Opname::where('store_id', $storeId)
            ->where('period_month', $refMonth)
            ->where('period_year',  $refYear)
            ->where('period_type',  'end_month')
            ->where('status',       'approved')
            ->first();

        // HPP Aktual butuh SO Awal & SO Akhir. Bila salah satu belum ada → stop.
        if (!$openingOpname || !$closingOpname) {
            $missing = [];
            if (!$openingOpname) $missing[] = 'SO Awal (opname akhir ' . $prevPeriod->isoFormat('MMMM Y') . ')';
            if (!$closingOpname) $missing[] = 'SO Akhir (opname akhir ' . Carbon::create($refYear, $refMonth, 1)->isoFormat('MMMM Y') . ')';
            return compact('store', 'orderDate', 'deliveryDate', 'coverageEnd', 'daysToCover',
                'leadTimeDays', 'refMonth', 'refYear', 'daysInRef', 'bufferPct',
                'stockSource', 'selectedOpname', 'usageSource')
                + ['tableData' => [],
                   'message' => 'HPP Aktual belum bisa dihitung untuk bulan referensi ini — lengkapi & approve '
                       . implode(' dan ', $missing) . ' terlebih dahulu.'];
        }

        // Semua komponen konsumsi disimpan PER (bahan × kemasan), sama alasannya
        // dengan stok di atas: 1 bahan bisa punya beberapa ukuran dus, jadi base
        // unit-nya tidak boleh digabung dulu sebelum dibagi ukuran dus.
        $perPkg = fn($rows) => collect($rows)
            ->mapWithKeys(fn($r) => [$r->ingredient_id . '-' . ($r->packaging_id ?: 0) => (float)$r->t])
            ->all();

        // SO Awal & SO Akhir (base)
        $openingMap = $perPkg(OpnameItem::where('opname_id', $openingOpname->id)
            ->selectRaw('ingredient_id, packaging_id, SUM(physical_qty) t')
            ->groupBy('ingredient_id', 'packaging_id')->get());
        $closingMap = $perPkg(OpnameItem::where('opname_id', $closingOpname->id)
            ->selectRaw('ingredient_id, packaging_id, SUM(physical_qty) t')
            ->groupBy('ingredient_id', 'packaging_id')->get());

        // Pembelian / barang masuk bulan referensi (base)
        $purchaseMap = $perPkg(DB::table('mutation_items as mi')
            ->join('mutations as m', 'm.id', '=', 'mi.mutation_id')
            ->where('m.destination_store_id', $storeId)
            ->where('m.status', 'confirmed')
            ->whereBetween(DB::raw('COALESCE(m.delivery_date, m.transaction_date)'), [$refStart, $refEnd])
            ->whereIn('m.type', ['purchase_zhisheng', 'purchase_supplier', 'sale_internal', 'sale_external'])
            ->selectRaw('mi.ingredient_id, mi.packaging_id, SUM(mi.total_in_base) as t')
            ->groupBy('mi.ingredient_id', 'mi.packaging_id')->get());

        // Transfer keluar (base) — toko ini sebagai sumber (sama dgn HPP Aktual)
        $salesOutMap = $perPkg(DB::table('mutation_items as mi')
            ->join('mutations as m', 'm.id', '=', 'mi.mutation_id')
            ->where('m.source_store_id', $storeId)
            ->where('m.status', 'confirmed')
            ->whereBetween(DB::raw('COALESCE(m.delivery_date, m.transaction_date)'), [$refStart, $refEnd])
            ->whereIn('m.type', ['sale_internal', 'sale_external_out'])
            ->selectRaw('mi.ingredient_id, mi.packaging_id, SUM(mi.total_in_base) as t')
            ->groupBy('mi.ingredient_id', 'mi.packaging_id')->get());

        // Semua bahan AKTIF yang dipasok SUPPLIER PUSAT ikut ditampilkan - bukan
        // hanya yang terpakai di bulan referensi. Bahan tanpa konsumsi tetap muncul
        // (konsumsi 0) supaya stoknya terlihat & bisa diorder manual bila perlu.
        // Bahan yang hanya dipasok supplier lokal tidak diorder ke pusat, jadi
        // tidak ditampilkan di sini.
        $pusatAktif = fn($q) => $q->where('is_active', true)
            ->whereHas('supplier', fn($s) => $s->where('type', 'zhisheng'));

        $catSort     = IngredientCategory::pluck('sort_order', 'name')->toArray();
        $ingredients = Ingredient::with(['packagings' => fn($q) => $pusatAktif($q)->orderBy('id')->with('supplier')])
            ->where('is_active', true)
            ->where('type', '!=', 'semi_finished')
            ->whereHas('packagings', $pusatAktif)
            ->get()
            ->sort(function ($a, $b) use ($catSort) {
                $ai = $catSort[$a->category] ?? 9999;
                $bi = $catSort[$b->category] ?? 9999;
                return $ai !== $bi ? $ai <=> $bi : $a->id <=> $b->id;
            })
            ->values();

        $tableData = [];

        // Ukuran dus (base per dus) untuk SEMUA kemasan, bukan hanya kemasan pusat —
        // stok/konsumsi bisa saja tercatat di kemasan lain milik bahan yang sama.
        $konv = IngredientPackaging::get(['id', 'crate_to_pack', 'pack_to_base'])
            ->mapWithKeys(fn($p) => [$p->id => [
                'ctp' => (float)$p->crate_to_pack,
                'ptb' => (float)$p->pack_to_base,
                'ctb' => (float)$p->crate_to_pack * (float)$p->pack_to_base,
            ]])->all();

        // "bahan-kemasan" => base  →  [bahan][kemasan] => base
        $byIng = function (array $map) {
            $out = [];
            foreach ($map as $k => $v) {
                [$i, $p] = array_pad(explode('-', (string)$k, 2), 2, 0);
                $out[(int)$i][(int)$p] = ($out[(int)$i][(int)$p] ?? 0) + (float)$v;
            }
            return $out;
        };
        $openingMap  = $byIng($openingMap);
        $closingMap  = $byIng($closingMap);
        $purchaseMap = $byIng($purchaseMap);
        $salesOutMap = $byIng($salesOutMap);
        $stockByPkg  = $byIng($stockByPkg);

        foreach ($ingredients as $ing) {
            // Kemasan yang dipakai = kemasan dari supplier pusat (relasi sudah difilter),
            // supaya ukuran dus & konversinya sesuai barang yang benar-benar diorder.
            $pkg = $ing->packagings->first();
            if (!$pkg || $pkg->crate_to_pack <= 0 || $pkg->pack_to_base <= 0) continue;

            $crateToBase = (float)$pkg->crate_to_pack * (float)$pkg->pack_to_base;

            // Ubah base → DUS memakai ukuran dus KEMASANNYA SENDIRI. Kemasan yang
            // tidak dikenal / tidak berkemasan jatuh ke ukuran dus kemasan order.
            // Inilah inti perbaikannya: dulu semua base dijumlahkan dulu lalu dibagi
            // ukuran dus kemasan pertama saja, sehingga bahan dengan >1 ukuran dus
            // (mis. Big Bag @9 pack vs @25 pack) sisanya salah hitung.
            $keDus = function (array $map) use ($ing, $konv, $crateToBase) {
                $dus = 0.0;
                foreach ($map[$ing->id] ?? [] as $pid => $base) {
                    $ctb = ($pid && ($konv[$pid]['ctb'] ?? 0) > 0) ? $konv[$pid]['ctb'] : $crateToBase;
                    $dus += $base / $ctb;
                }
                return $dus;
            };

            // Khusus STOK: hanya pack utuh yang dihitung. Sisa eceran di dalam pack
            // yang sudah dibuka bukan stok siap pakai — Saldo Stok pun menampilkan
            // floor(sisa / pack_to_base). Tanpa ini, angka dus di sini lebih besar
            // daripada stok yang sama di modul lain.
            $keDusStok = function (array $map) use ($ing, $konv, $crateToBase) {
                $dus = 0.0;
                foreach ($map[$ing->id] ?? [] as $pid => $base) {
                    $ptb = $konv[$pid]['ptb'] ?? 0;
                    $ctp = $konv[$pid]['ctp'] ?? 0;
                    if ($base > 0 && $ptb > 0 && $ctp > 0) {
                        $dus += floor(round($base / $ptb, 6)) / $ctp;
                        continue;
                    }
                    $ctb = ($pid && ($konv[$pid]['ctb'] ?? 0) > 0) ? $konv[$pid]['ctb'] : $crateToBase;
                    $dus += $base / $ctb;
                }
                return $dus;
            };

            // Konsumsi acuan, identik dengan HPP Aktual:
            //   SO Awal + barang masuk - transfer keluar - SO Akhir
            // Hasil minus (stok justru bertambah) dianggap 0, bukan dibuang.
            $consumDus = $keDus($openingMap) + $keDus($purchaseMap)
                       - $keDus($salesOutMap) - $keDus($closingMap);
            $totalDusRef = max(0.0, $consumDus);

            // konsumsi sebulan -> dibagi rata ke jumlah hari bulan referensi
            $avgDailyDus  = $totalDusRef / $daysInRef;
            $stockDus     = $keDusStok($stockByPkg);

            $grossDus     = $avgDailyDus * $daysToCover;
            $grossWithBuf = $grossDus * (1 + $bufferPct / 100);
            $netDusRaw    = max(0, $grossWithBuf - $stockDus);
            // Kebutuhan < 0,1 dus dianggap nol (jangan dibulatkan ke atas)
            $netDus       = $netDusRaw < 0.1 ? 0 : (int) ceil($netDusRaw);

            $tableData[] = (object)[
                'ingredient'      => $ing,
                'packaging'       => $pkg,
                'ref_total_dus'   => round($totalDusRef, 2),
                'avg_daily_dus'   => round($avgDailyDus, 3),
                'active_days'     => $daysInRef,
                'stock_dus'       => round($stockDus, 2),
                'gross_dus'       => round($grossDus, 2),
                'buffer_dus'      => round($grossWithBuf - $grossDus, 2),
                'net_dus'         => $netDus,
            ];
        }

        return compact(
            'store', 'tableData', 'orderDate', 'deliveryDate', 'coverageEnd', 'daysToCover',
            'leadTimeDays', 'refMonth', 'refYear', 'daysInRef', 'bufferPct',
            'stockSource', 'selectedOpname', 'usageSource'
        );
    }
}
