<?php
namespace App\Http\Controllers\Sales;
use App\Http\Controllers\Controller;
use App\Models\{MonthlySale, MonthlyRevenue, Store, Menu, Ingredient};
use App\Services\MonthLockService;
use App\Exports\ArrayExport;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpSpreadsheet\Style\{Fill, Alignment};

class MonthlySaleController extends Controller
{
    public function index(Request $request)
    {
        $storeIds = auth()->user()->accessibleStoreIds();
        $stores   = auth()->user()->accessibleStores();

        // Kumpulkan semua periode unik dari KEDUA tabel (revenue & sales)
        $revQuery  = MonthlyRevenue::selectRaw('store_id, month, year, period_type')
            ->whereIn('store_id', $storeIds);
        $saleQuery = MonthlySale::selectRaw('store_id, month, year, period_type')
            ->whereIn('store_id', $storeIds);

        if ($request->store_id) {
            $revQuery->where('store_id', $request->store_id);
            $saleQuery->where('store_id', $request->store_id);
        }
        // Filter by Tipe periode + Bulan + Tahun (bukan range tanggal)
        if ($request->period_type) {
            $revQuery->where('period_type', $request->period_type);
            $saleQuery->where('period_type', $request->period_type);
        }
        if ($request->month) {
            $revQuery->where('month', (int) $request->month);
            $saleQuery->where('month', (int) $request->month);
        }
        if ($request->year) {
            $revQuery->where('year', (int) $request->year);
            $saleQuery->where('year', (int) $request->year);
        }

        // UNION kedua sumber → periode unik
        $periods = $revQuery->union($saleQuery)->get()
            ->unique(fn($r) => "{$r->store_id}_{$r->month}_{$r->year}_{$r->period_type}")
            ->sortByDesc(fn($r) => $r->year * 10000 + $r->month * 100 + ($r->period_type === 'end_month' ? 1 : 0))
            ->values();

        // Ambil aggregat sales & omset per periode
        // Menu yang saklarnya dimatikan (add on & packaging cup) tidak ikut dijumlah
        // ke total terjual. Baris tetap terhitung di menu_count.
        $salesMap = MonthlySale::selectRaw(
                'monthly_sales.store_id, monthly_sales.month, monthly_sales.year, monthly_sales.period_type,
                 COUNT(*) as menu_count,
                 SUM(CASE WHEN COALESCE(menus.count_in_total, 1) = 1 THEN monthly_sales.total_sold ELSE 0 END) as total_sold')
            ->leftJoin('menus', 'menus.id', '=', 'monthly_sales.menu_id')
            ->whereIn('monthly_sales.store_id', $storeIds)
            ->groupBy('monthly_sales.store_id', 'monthly_sales.month', 'monthly_sales.year', 'monthly_sales.period_type')
            ->get()->keyBy(fn($r) => "{$r->store_id}_{$r->month}_{$r->year}_{$r->period_type}");

        $revenueMap = MonthlyRevenue::whereIn('store_id', $storeIds)->get()
            ->keyBy(fn($r) => "{$r->store_id}_{$r->month}_{$r->year}_{$r->period_type}");

        // Bangun groups dengan struktur yang sama seperti sebelumnya
        $groups = $periods->map(function ($p) use ($salesMap, $revenueMap) {
            $key  = "{$p->store_id}_{$p->month}_{$p->year}_{$p->period_type}";
            $sale = $salesMap[$key] ?? null;
            return (object) [
                'store_id'    => $p->store_id,
                'month'       => $p->month,
                'year'        => $p->year,
                'period_type' => $p->period_type,
                'menu_count'  => $sale?->menu_count ?? 0,
                'total_sold'  => $sale?->total_sold ?? 0,
            ];
        });

        return view('sales.index', compact('groups', 'stores', 'revenueMap'));
    }

    public function create()
    {
        $stores = auth()->user()->accessibleStores();
        $menus  = Menu::where('menus.is_active',true)->with('menuCategory')->orderedByCategory()->get();
        return view('sales.create', compact('stores','menus'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'store_id'      => 'required|exists:stores,id',
            'month'         => 'required|integer|between:1,12',
            'year'          => 'required|integer|min:2020',
            'period_type'   => 'required|in:end_month,mid_month',
            'gross_revenue' => 'nullable|numeric|min:0',
            'tiktok_diff'   => 'nullable|numeric',   // boleh minus
            'items'         => 'nullable|array',
            'items.*.menu_id'    => 'required_with:items|exists:menus,id',
            'items.*.total_sold' => 'required_with:items|integer|min:0',
        ]);

        $periodType = $request->period_type;
        $month      = (int)$request->month;
        $year       = (int)$request->year;

        // ── Lock check ──────────────────────────────────────────────────────────
        if (MonthLockService::isPastLock($month, $year)) {
            // Cari MonthlyRevenue yg sudah ada untuk cek unlock per record
            $existingRevenue = MonthlyRevenue::where([
                'store_id'    => $request->store_id,
                'month'       => $month,
                'year'        => $year,
                'period_type' => $periodType,
            ])->first();

            $isLocked = $existingRevenue
                ? MonthLockService::isLocked('monthly_sale', $existingRevenue->id, $month, $year)
                : !auth()->user()->isSuperAdmin();

            if ($isLocked) {
                return back()->withInput()
                    ->with('error', MonthLockService::lockMessage($month, $year));
            }
        }

        // Simpan omset per periode. Kolom total_revenue = bruto + selisih TikTok,
        // supaya laporan & HPP yang sudah baca kolom itu langsung dapat angka final.
        $gross  = (float)($request->gross_revenue ?? 0);
        $tiktok = (float)($request->tiktok_diff ?? 0);
        if ($gross > 0 || $tiktok != 0) {
            MonthlyRevenue::updateOrCreate(
                ['store_id'    => $request->store_id,
                 'month'       => $request->month,
                 'year'        => $request->year,
                 'period_type' => $periodType],
                ['total_revenue' => $gross + $tiktok,
                 'tiktok_diff'   => $tiktok,
                 'recorded_by'   => auth()->id()]
            );
        }

        // Simpan qty terjual per menu
        foreach ($request->items ?? [] as $item) {
            $qty = (int)($item['total_sold'] ?? 0);
            if ($qty === 0) continue;

            MonthlySale::updateOrCreate(
                ['store_id'    => $request->store_id,
                 'menu_id'     => $item['menu_id'],
                 'month'       => $request->month,
                 'year'        => $request->year,
                 'period_type' => $periodType],
                ['total_sold'  => $qty,
                 'recorded_by' => auth()->id()]
            );
        }

        return redirect()->route('sales.hpp.index', [
            'store_id'    => $request->store_id,
            'month'       => $request->month,
            'year'        => $request->year,
            'period_type' => $periodType,
        ])->with('success', 'Data penjualan & omset disimpan.');
    }

    // ── Helper: ambil params group dari request ──────────────────────────────
    private function groupParams(Request $request): array
    {
        return [
            'store_id'    => (int)$request->store_id,
            'month'       => (int)$request->month,
            'year'        => (int)$request->year,
            'period_type' => $request->period_type,
        ];
    }

    private function groupSales(array $p)
    {
        return MonthlySale::with('menu.menuCategory')
            ->where($p)->get();
    }

    // ── Show detail group ─────────────────────────────────────────────────────
    public function periodShow(Request $request)
    {
        $p       = $this->groupParams($request);
        $sales   = $this->groupSales($p);
        $revenue = MonthlyRevenue::where($p)->first();
        if ($sales->isEmpty() && !$revenue) abort(404);
        $store   = Store::findOrFail($p['store_id']);
        return view('sales.show', compact('sales', 'store', 'revenue', 'p'));
    }

    // ── Edit group ────────────────────────────────────────────────────────────
    public function periodEdit(Request $request)
    {
        $p       = $this->groupParams($request);
        $sales   = $this->groupSales($p);
        $revenue = MonthlyRevenue::where($p)->first();
        if ($sales->isEmpty() && !$revenue) abort(404);
        $store   = Store::findOrFail($p['store_id']);
        $menus   = Menu::where('menus.is_active', true)->with('menuCategory')->orderedByCategory()->get();
        return view('sales.edit', compact('sales', 'store', 'revenue', 'menus', 'p'));
    }

    // ── Update group ──────────────────────────────────────────────────────────
    public function periodUpdate(Request $request)
    {
        // Angka dikirim ber-titik ("1.200"). JS sudah melucuti titik sebelum submit,
        // tapi dibersihkan lagi di sini supaya tetap benar kalau JS gagal jalan.
        $bersih = fn ($v) => ($v === null || $v === '') ? $v : preg_replace('/[^0-9]/', '', (string) $v);
        // Selisih TikTok boleh minus, jadi tanda "-" di depan ikut dipertahankan.
        $bersihMinus = function ($v) {
            if ($v === null || $v === '') return $v;
            $neg = str_starts_with(trim((string) $v), '-');
            $n   = preg_replace('/[^0-9]/', '', (string) $v);
            return $n === '' ? $n : ($neg ? '-' : '') . $n;
        };
        $request->merge([
            'gross_revenue' => $bersih($request->input('gross_revenue')),
            'tiktok_diff'   => $bersihMinus($request->input('tiktok_diff')),
            'items'         => collect($request->input('items', []))
                ->map(fn ($it) => array_merge($it, ['total_sold' => $bersih($it['total_sold'] ?? null)]))
                ->all(),
        ]);

        $request->validate([
            'store_id'      => 'required|exists:stores,id',
            'month'         => 'required|integer|between:1,12',
            'year'          => 'required|integer|min:2020',
            'period_type'   => 'required|in:end_month,mid_month',
            'gross_revenue' => 'nullable|numeric|min:0',
            'tiktok_diff'   => 'nullable|numeric',   // boleh minus
            'items'         => 'required|array|min:1',
            'items.*.menu_id'    => 'required|exists:menus,id',
            'items.*.total_sold' => 'required|integer|min:0',
        ]);

        $p = $this->groupParams($request);

        if (MonthLockService::isPastLock($p['month'], $p['year'])) {
            if (!auth()->user()->isSuperAdmin()) {
                return back()->with('error', MonthLockService::lockMessage($p['month'], $p['year']));
            }
        }

        // Update omset — kolom total_revenue menyimpan hasil akhir (bruto + selisih)
        $gross  = (float)($request->gross_revenue ?? 0);
        $tiktok = (float)($request->tiktok_diff ?? 0);
        MonthlyRevenue::updateOrCreate($p, [
            'total_revenue' => $gross + $tiktok,
            'tiktok_diff'   => $tiktok,
            'recorded_by'   => auth()->id(),
        ]);

        // Hapus semua record lama lalu simpan ulang
        MonthlySale::where($p)->delete();
        foreach ($request->items as $item) {
            $qty = (int)($item['total_sold'] ?? 0);
            if ($qty === 0) continue;
            MonthlySale::create(array_merge($p, [
                'menu_id'     => $item['menu_id'],
                'total_sold'  => $qty,
                'recorded_by' => auth()->id(),
            ]));
        }

        return redirect()->route('sales.monthly.index', [
            'month' => $p['month'], 'year' => $p['year'],
        ])->with('success', 'Data penjualan berhasil diupdate.');
    }

    // ── Destroy group ─────────────────────────────────────────────────────────
    public function periodDestroy(Request $request)
    {
        $p = $this->groupParams($request);

        if (MonthLockService::isPastLock($p['month'], $p['year'])) {
            if (!auth()->user()->isSuperAdmin()) {
                return back()->with('error', MonthLockService::lockMessage($p['month'], $p['year']));
            }
        }

        MonthlySale::where($p)->delete();
        MonthlyRevenue::where($p)->delete();

        return redirect()->route('sales.monthly.index', [
            'month' => $p['month'], 'year' => $p['year'],
        ])->with('success', 'Data penjualan berhasil dihapus.');
    }

    public function export(Request $request)
    {
        $storeIds = auth()->user()->accessibleStoreIds();
        $query    = MonthlySale::with(['store','menu.menuCategory'])->whereIn('store_id', $storeIds);
        if ($request->store_id)    $query->where('store_id', $request->store_id);
        if ($request->month)       $query->where('month', $request->month);
        if ($request->year)        $query->where('year', $request->year);
        if ($request->period_type) $query->where('period_type', $request->period_type);
        $sales = $query->orderBy('year')->orderBy('month')->orderBy('store_id')->get();

        $data = [['Toko', 'Bulan', 'Tahun', 'Periode', 'Kategori', 'Menu', 'Qty Terjual', 'Pendapatan']];
        foreach ($sales as $s) {
            $data[] = [
                $s->store->name,
                \Carbon\Carbon::create($s->year, $s->month)->isoFormat('MMMM'),
                $s->year,
                $s->period_type === 'mid_month' ? 'Tengah Bulan' : 'Akhir Bulan',
                $s->menu?->menuCategory?->name ?? '-',
                $s->menu?->name ?? '-',
                $s->total_sold,
                $s->total_revenue ?? 0,
            ];
        }

        return Excel::download(new ArrayExport($data), 'penjualan_' . now()->format('Y-m-d') . '.xlsx');
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  TEMPLATE DOWNLOAD
    // ═══════════════════════════════════════════════════════════════════════
    public function downloadTemplate(Request $request)
    {
        $request->validate([
            'store_id'    => 'required|exists:stores,id',
            'month'       => 'required|integer|between:1,12',
            'year'        => 'required|integer|min:2020',
            'period_type' => 'required|in:mid_month,end_month',
        ]);

        $storeId     = (int)$request->store_id;
        $month       = (int)$request->month;
        $year        = (int)$request->year;
        $periodType  = $request->period_type;
        $store       = Store::findOrFail($storeId);
        $periodLabel = $periodType === 'mid_month' ? 'Tengah Bulan (1-15)' : 'Akhir Bulan (1-30/31)';
        $monthNames  = ['','Januari','Februari','Maret','April','Mei','Juni',
                        'Juli','Agustus','September','Oktober','November','Desember'];

        // Existing qty untuk pre-fill
        $existingMap = MonthlySale::where([
                'store_id' => $storeId, 'month' => $month,
                'year' => $year, 'period_type' => $periodType,
            ])->pluck('total_sold', 'menu_id');

        $existingRev     = MonthlyRevenue::where([
                'store_id' => $storeId, 'month' => $month,
                'year' => $year, 'period_type' => $periodType,
            ])->first();
        $existingTiktok  = (float) ($existingRev->tiktok_diff ?? 0);
        $existingRevenue = (float) ($existingRev->gross_revenue ?? 0);   // bruto

        $menus = Menu::where('menus.is_active', true)->with('menuCategory')->orderedByCategory()->get();

        // ── Build spreadsheet ───────────────────────────────────────────────
        $ss = new Spreadsheet();
        $ws = $ss->getActiveSheet();
        $ws->setTitle('Penjualan');

        // Baris 1: metadata
        $ws->setCellValue('A1', 'METADATA');
        $ws->setCellValue('B1', $storeId);
        $ws->setCellValue('C1', $month);
        $ws->setCellValue('D1', $year);
        $ws->setCellValue('E1', $periodType);
        $ws->setCellValue('F1', $existingRevenue);   // bruto
        $ws->setCellValue('G1', $existingTiktok);    // selisih tiktok
        $ws->getStyle('A1:G1')->applyFromArray([
            'font' => ['size' => 8, 'color' => ['rgb' => 'AAAAAA']],
        ]);

        // Baris 2: judul
        $title = "TEMPLATE PENJUALAN - {$store->name} - {$monthNames[$month]} {$year} - {$periodLabel}";
        $ws->setCellValue('A2', $title);
        $ws->mergeCells('A2:E2');
        $ws->getStyle('A2')->getFont()->setBold(true)->setSize(12);
        $ws->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Baris 3: omset bruto — Baris 4: selisih TikTok (boleh minus)
        $ws->setCellValue('A3', 'Omset Bruto (Rp):');
        $ws->setCellValue('B3', $existingRevenue ?: '');
        $ws->setCellValue('A4', 'Selisih TikTok (Rp):');
        $ws->setCellValue('B4', $existingTiktok ?: '');
        $ws->getStyle('A3:A4')->getFont()->setBold(true);
        $ws->getStyle('B3:B4')->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => 'FFFDE7']],
            'font' => ['bold' => true],
        ]);

        // Baris 5: header kolom
        $ws->setCellValue('A5', 'ID Menu (jgn ubah)');
        $ws->setCellValue('B5', 'Nama Menu');
        $ws->setCellValue('C5', 'Kategori');
        $ws->setCellValue('D5', 'QTY TERJUAL (pcs)');
        $ws->getStyle('A5:D5')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => '1e3a5f']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        // Baris 6+: menu
        $rowNum = 6;
        foreach ($menus as $menu) {
            $ws->setCellValueByColumnAndRow(1, $rowNum, $menu->id);
            $ws->setCellValueByColumnAndRow(2, $rowNum, $menu->name);
            $ws->setCellValueByColumnAndRow(3, $rowNum, $menu->menuCategory?->name ?? '-');
            $ws->setCellValueByColumnAndRow(4, $rowNum, $existingMap[$menu->id] ?? '');

            $ws->getStyle("A{$rowNum}:C{$rowNum}")->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => 'F2F2F2']],
                'font' => ['color' => ['rgb' => '888888']],
            ]);
            $ws->getStyle("D{$rowNum}")->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => 'FFFDE7']],
                'font' => ['bold' => true],
            ]);
            $rowNum++;
        }

        $ws->getColumnDimension('A')->setWidth(18);
        $ws->getColumnDimension('B')->setWidth(36);
        $ws->getColumnDimension('C')->setWidth(20);
        $ws->getColumnDimension('D')->setWidth(22);
        $ws->freezePane('D6');

        $filename = "template_penjualan_{$store->name}_{$year}-{$month}_{$periodType}.xlsx";
        $writer   = new XlsxWriter($ss);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  IMPORT FORM
    // ═══════════════════════════════════════════════════════════════════════
    public function importForm()
    {
        $stores = auth()->user()->accessibleStores();
        return view('sales.import', compact('stores'));
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  IMPORT PREVIEW — parse file, tampilkan untuk konfirmasi
    // ═══════════════════════════════════════════════════════════════════════
    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls|max:5120']);

        $path = $request->file('file')->getRealPath();
        $ss   = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $ws   = $ss->getActiveSheet();

        // ── Baca metadata ────────────────────────────────────────────────────
        if ($ws->getCell('A1')->getValue() !== 'METADATA') {
            return back()->withErrors(['file' => 'File tidak valid: pastikan menggunakan template yang benar.']);
        }
        $storeId    = (int)$ws->getCell('B1')->getValue();
        $month      = (int)$ws->getCell('C1')->getValue();
        $year       = (int)$ws->getCell('D1')->getValue();
        $periodType = $ws->getCell('E1')->getValue();
        $revenue    = (float)($ws->getCell('F1')->getValue() ?? 0);   // bruto (cadangan)
        $tiktok     = (float)($ws->getCell('G1')->getValue() ?? 0);   // selisih (cadangan)

        // Template lama: baris 3 omset, header di baris 4, menu mulai baris 5.
        // Template baru: baris 3 bruto, baris 4 selisih TikTok, header baris 5, menu baris 6.
        // Header dicari lewat labelnya supaya template lama yang sudah terlanjur
        // di-download tetap bisa diimpor tanpa perlu download ulang.
        $headerRow = 4;
        for ($r = 3; $r <= 8; $r++) {
            if (str_starts_with(trim((string) $ws->getCell("A{$r}")->getValue()), 'ID Menu')) {
                $headerRow = $r;
                break;
            }
        }

        // Baca label omset di baris-baris sebelum header (posisinya beda antar versi)
        for ($r = 3; $r < $headerRow; $r++) {
            $label = strtolower(trim((string) $ws->getCell("A{$r}")->getValue()));
            $nilai = $ws->getCell("B{$r}")->getValue();
            if (!is_numeric($nilai)) continue;

            if (str_contains($label, 'tiktok')) {
                $tiktok = (float) $nilai;
            } elseif ((float) $nilai > 0) {
                $revenue = (float) $nilai;   // "Omset Bruto" / "Total Omset Periode" (template lama)
            }
        }

        // Validasi metadata
        $storeIds = auth()->user()->accessibleStoreIds();
        if (!in_array($storeId, $storeIds)) {
            return back()->withErrors(['file' => 'Toko tidak ditemukan atau tidak dapat diakses.']);
        }
        if (!in_array($periodType, ['mid_month', 'end_month'])) {
            return back()->withErrors(['file' => 'Tipe periode tidak valid dalam file.']);
        }
        if ($month < 1 || $month > 12 || $year < 2020) {
            return back()->withErrors(['file' => 'Bulan/tahun tidak valid dalam file.']);
        }

        if (!auth()->user()->isSuperAdmin() && MonthLockService::isPastLock($month, $year)) {
            return back()->withErrors(['file' => MonthLockService::lockMessage($month, $year)]);
        }

        // ── Baca baris menu (tepat setelah baris header) ────────────────────
        $errors  = [];
        $items   = [];
        $menuMap = Menu::where('is_active', true)->with('menuCategory')->get()->keyBy('id');
        $rowNum  = $headerRow + 1;
        $maxRow  = $ws->getHighestDataRow();

        while ($rowNum <= $maxRow) {
            $menuId = $ws->getCellByColumnAndRow(1, $rowNum)->getValue();
            $qty    = $ws->getCellByColumnAndRow(4, $rowNum)->getValue();
            $rowNum++;

            if ($menuId === '' || $menuId === null) continue;
            $menuId = (int)$menuId;

            if (!isset($menuMap[$menuId])) {
                $errors[] = "Baris {$rowNum}: menu ID {$menuId} tidak ditemukan atau tidak aktif.";
                continue;
            }
            if ($qty !== '' && $qty !== null && (!is_numeric($qty) || (int)$qty < 0)) {
                $errors[] = "Baris {$rowNum}: qty harus angka ≥ 0 (dapat dikosongkan).";
                continue;
            }

            $qty = ($qty !== '' && $qty !== null) ? (int)$qty : 0;
            if ($qty > 0) {
                $menu    = $menuMap[$menuId];
                $items[] = [
                    'menu_id'   => $menuId,
                    'menu_name' => $menu->name,
                    'category'  => $menu->menuCategory?->name ?? '-',
                    'total_sold'=> $qty,
                    // add on & packaging cup: tidak ikut dijumlah ke total menu terjual
                    'count_in_total' => (bool) ($menu->count_in_total ?? true),
                ];
            }
        }

        if (!empty($errors)) {
            return back()->withErrors(['file' => implode(' | ', $errors)]);
        }

        $store      = Store::findOrFail($storeId);
        $monthNames = ['','Januari','Februari','Maret','April','Mei','Juni',
                       'Juli','Agustus','September','Oktober','November','Desember'];

        $preview = [
            'store_id'    => $storeId,
            'store_name'  => $store->name,
            'month'       => $month,
            'month_name'  => $monthNames[$month],
            'year'        => $year,
            'period_type' => $periodType,
            'revenue'     => $revenue,   // bruto
            'tiktok_diff' => $tiktok,
            'items'       => $items,
        ];

        return view('sales.import', compact('preview'));
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  IMPORT COMMIT — simpan setelah user konfirmasi preview
    // ═══════════════════════════════════════════════════════════════════════
    public function importCommit(Request $request)
    {
        // Qty & omset boleh dikoreksi di halaman pratinjau, dan tampil dgn titik
        // pemisah ribuan. Bersihkan titik di sini supaya tetap valid walau JS gagal.
        $bersih = fn($v) => ($v === null || $v === '') ? $v : preg_replace('/[^0-9]/', '', (string) $v);
        // Selisih TikTok boleh minus, jadi tanda "-" di depan ikut dipertahankan.
        $bersihMinus = function ($v) {
            if ($v === null || $v === '') return $v;
            $neg = str_starts_with(trim((string) $v), '-');
            $n   = preg_replace('/[^0-9]/', '', (string) $v);
            return $n === '' ? $n : ($neg ? '-' : '') . $n;
        };
        $request->merge([
            'gross_revenue' => $bersih($request->input('gross_revenue')),
            'tiktok_diff' => $bersihMinus($request->input('tiktok_diff')),
            'items'       => collect($request->input('items', []))
                ->map(fn($it) => array_merge($it, ['total_sold' => $bersih($it['total_sold'] ?? null)]))
                ->all(),
        ]);

        $request->validate([
            'store_id'    => 'required|exists:stores,id',
            'month'       => 'required|integer|between:1,12',
            'year'        => 'required|integer|min:2020',
            'period_type' => 'required|in:mid_month,end_month',
            'gross_revenue' => 'nullable|numeric|min:0',  // omset bruto
            'tiktok_diff' => 'nullable|numeric',          // boleh minus
            'items'       => 'nullable|array',
            'items.*.menu_id'    => 'required|exists:menus,id',
            'items.*.total_sold' => 'required|integer|min:0',
        ]);

        $storeId    = (int)$request->store_id;
        $month      = (int)$request->month;
        $year       = (int)$request->year;
        $periodType = $request->period_type;
        $gross      = (float)($request->gross_revenue ?? 0); // bruto
        $tiktok     = (float)($request->tiktok_diff ?? 0);  // selisih TikTok
        $revenue    = $gross + $tiktok;                     // total omset

        $storeIds = auth()->user()->accessibleStoreIds();
        if (!in_array($storeId, $storeIds)) abort(403);

        if (!auth()->user()->isSuperAdmin() && MonthLockService::isPastLock($month, $year)) {
            return back()->with('error', MonthLockService::lockMessage($month, $year));
        }

        $p = ['store_id' => $storeId, 'month' => $month, 'year' => $year, 'period_type' => $periodType];

        if ($gross > 0 || $tiktok != 0) {
            MonthlyRevenue::updateOrCreate($p, [
                'total_revenue' => $revenue,
                'tiktok_diff'   => $tiktok,
                'recorded_by'   => auth()->id(),
            ]);
        }

        MonthlySale::where($p)->delete();
        foreach ($request->items ?? [] as $item) {
            $qty = (int) $item['total_sold'];
            if ($qty === 0) continue;   // qty dikoreksi jadi 0 → tidak perlu disimpan
            MonthlySale::create(array_merge($p, [
                'menu_id'     => $item['menu_id'],
                'total_sold'  => $qty,
                'recorded_by' => auth()->id(),
            ]));
        }

        return redirect()->route('sales.monthly.index', ['month' => $month, 'year' => $year])
            ->with('success', 'Data penjualan berhasil diimport.');
    }
}
