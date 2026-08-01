<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Analisa HPP - {{ $store->name }} - {{ $monthLabel }}</title>
<style>
    /* ── Halaman ─────────────────────────────────────────────────────────── */
    @page { size: A4 portrait; margin: 14mm 12mm 16mm 12mm; }

    * { box-sizing: border-box; }
    body {
        font-family: "Segoe UI", Arial, Helvetica, sans-serif;
        font-size: 9.5pt; color: #1e293b; margin: 0; background: #f1f5f9;
    }
    .sheet { max-width: 210mm; margin: 0 auto; background: #fff; padding: 14mm 12mm; }

    /* ── Kop ─────────────────────────────────────────────────────────────── */
    .kop { display: flex; justify-content: space-between; align-items: flex-start;
           border-bottom: 2.5pt solid #006275; padding-bottom: 8pt; margin-bottom: 12pt; }
    .kop h1 { margin: 0 0 2pt; font-size: 17pt; letter-spacing: -.3pt; color: #0f172a; }
    .kop .sub { font-size: 9pt; color: #475569; }
    .kop .brand { text-align: right; font-size: 8pt; color: #64748b; line-height: 1.5; }
    .kop .brand strong { display: block; font-size: 12pt; color: #006275; letter-spacing: .5pt; }

    .meta { display: flex; gap: 18pt; flex-wrap: wrap; font-size: 8.5pt;
            color: #475569; margin-bottom: 12pt; }
    .meta b { color: #0f172a; }

    /* ── Kartu ringkasan ─────────────────────────────────────────────────── */
    .cards { display: flex; gap: 6pt; margin-bottom: 14pt; }
    .card { flex: 1; border: .8pt solid #e2e8f0; border-radius: 4pt; padding: 7pt 8pt;
            background: #f8fafc; }
    .card .lbl { font-size: 7.5pt; color: #64748b; text-transform: uppercase;
                 letter-spacing: .4pt; margin-bottom: 3pt; }
    .card .val { font-size: 12.5pt; font-weight: 700; color: #0f172a; line-height: 1.15; }
    .card .sub { font-size: 7.5pt; color: #64748b; margin-top: 2pt; }
    .card.accent { background: #ecfeff; border-color: #a5f3fc; }
    .card.accent .val { color: #006275; }

    /* ── Judul bagian ────────────────────────────────────────────────────── */
    h2 { font-size: 10.5pt; margin: 14pt 0 6pt; padding-bottom: 3pt; color: #0f172a;
         border-bottom: 1pt solid #cbd5e1; }
    h2 .note { float: right; font-size: 8pt; font-weight: 400; color: #64748b; }

    /* ── Tabel ───────────────────────────────────────────────────────────── */
    table { width: 100%; border-collapse: collapse; font-size: 8.5pt; }
    thead th { background: #1e3a5f; color: #fff; font-weight: 600; text-align: left;
               padding: 4.5pt 5pt; border: .5pt solid #1e3a5f; white-space: nowrap; }
    tbody td { padding: 3.5pt 5pt; border: .5pt solid #e2e8f0; vertical-align: top; }
    tbody tr:nth-child(even) td { background: #f8fafc; }
    tfoot td { padding: 4.5pt 5pt; border: .5pt solid #cbd5e1; background: #eef2f7;
               font-weight: 700; }
    .r { text-align: right; white-space: nowrap; }
    .c { text-align: center; }
    .pos { color: #047857; }         /* hemat / untung */
    .neg { color: #b91c1c; }         /* boros / rugi  */
    .muted { color: #94a3b8; }
    .cat { background: #e2e8f0 !important; font-weight: 700; font-size: 8pt;
           text-transform: uppercase; letter-spacing: .3pt; }

    /* ── Catatan kaki ────────────────────────────────────────────────────── */
    .legend { margin-top: 10pt; padding: 7pt 9pt; background: #f8fafc;
              border: .8pt solid #e2e8f0; border-radius: 4pt; font-size: 8pt; color: #475569; }
    .legend b { color: #0f172a; }
    .ttd { margin-top: 22pt; display: flex; justify-content: flex-end; gap: 40pt;
           font-size: 8.5pt; text-align: center; color: #475569; }
    .ttd div { width: 150pt; }
    .ttd .line { margin-top: 34pt; border-top: .8pt solid #94a3b8; padding-top: 3pt; }

    /* ── Perilaku cetak ──────────────────────────────────────────────────── */
    .toolbar { max-width: 210mm; margin: 10pt auto 0; text-align: right; }
    .btn { display: inline-block; padding: 7pt 14pt; border-radius: 5pt; border: 0;
           background: #006275; color: #fff; font-size: 10pt; cursor: pointer;
           text-decoration: none; font-family: inherit; }
    .btn.sec { background: #fff; color: #334155; border: 1pt solid #cbd5e1; }

    thead { display: table-header-group; }    /* judul kolom terulang tiap halaman */
    tr, .card, .legend { page-break-inside: avoid; }
    h2 { page-break-after: avoid; }

    @media print {
        body { background: #fff; }
        .sheet { max-width: none; margin: 0; padding: 0; }
        .toolbar { display: none; }
    }
</style>
</head>
<body>

<div class="toolbar">
    <a href="{{ url()->previous() }}" class="btn sec">← Kembali</a>
    <button class="btn" onclick="window.print()">🖨 Cetak / Simpan PDF</button>
</div>

<div class="sheet">

    {{-- ══ KOP ══ --}}
    <div class="kop">
        <div>
            <h1>Laporan Analisa HPP</h1>
            <div class="sub">{{ $store->name }} &middot; {{ $monthLabel }} &middot; {{ $periodLabel }}</div>
        </div>
        <div class="brand">
            <strong>GLACIER</strong>
            Sistem Inventori &amp; HPP<br>
            sahabatglacier.online
        </div>
    </div>

    <div class="meta">
        <span><b>Toko:</b> {{ $store->name }}</span>
        <span><b>Periode:</b> {{ $monthLabel }} ({{ $periodLabel }})</span>
        <span><b>Dicetak:</b> {{ now()->isoFormat('D MMMM Y, HH:mm') }}</span>
        <span><b>Oleh:</b> {{ auth()->user()->name }}</span>
    </div>

    @php
        $rp  = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
        $pct = fn($v) => $v === null ? '—' : number_format((float) $v, 1, ',', '.') . '%';
        $n   = fn($v, $d = 0) => number_format((float) $v, $d, ',', '.');
    @endphp

    {{-- ══ RINGKASAN ══ --}}
    <div class="cards">
        <div class="card accent">
            <div class="lbl">Omset</div>
            <div class="val">{{ $rp($summary->omset) }}</div>
        </div>
        <div class="card">
            <div class="lbl">HPP Ideal</div>
            <div class="val">{{ $rp($summary->hpp_ideal) }}</div>
            <div class="sub">{{ $pct($summary->pct_hpp_ideal) }} dari omset</div>
        </div>
        <div class="card">
            <div class="lbl">HPP Aktual</div>
            <div class="val">{{ $summary->hpp_aktual === null ? '—' : $rp($summary->hpp_aktual) }}</div>
            <div class="sub">{{ $pct($summary->pct_hpp_aktual) }} dari omset</div>
        </div>
        <div class="card">
            <div class="lbl">Selisih HPP</div>
            @php $sel = $summary->selisih_hpp; @endphp
            <div class="val {{ $sel === null ? '' : ($sel >= 0 ? 'pos' : 'neg') }}">
                {{ $sel === null ? '—' : ($sel >= 0 ? '+' : '−') . $rp(abs($sel)) }}
            </div>
            <div class="sub">{{ $sel === null ? 'butuh opname' : ($sel >= 0 ? 'lebih hemat' : 'lebih boros') }}</div>
        </div>
        <div class="card">
            <div class="lbl">Margin Aktual</div>
            <div class="val">{{ $pct($summary->margin_aktual) }}</div>
            <div class="sub">ideal {{ $pct($summary->margin_ideal) }}</div>
        </div>
    </div>

    @unless($summary->has_opname)
        <div class="legend" style="border-color:#fed7aa;background:#fff7ed;color:#9a3412">
            <b>Perhatian:</b> belum ada stok opname disetujui untuk periode ini, sehingga
            <b>HPP Aktual belum bisa dihitung</b>. Angka yang tersaji baru HPP Ideal (berbasis resep &amp; penjualan).
        </div>
    @endunless

    {{-- ══ PER MENU ══ --}}
    <h2>Analisa per Menu <span class="note">{{ $menuRows->count() }} menu terjual</span></h2>
    <table>
        <thead>
            <tr>
                <th style="width:34%">Menu</th>
                <th class="r">Terjual</th>
                <th class="r">HPP / pcs</th>
                <th class="r">HPP Ideal</th>
                <th class="r">Kontribusi</th>
            </tr>
        </thead>
        <tbody>
            @php $totIdeal = $menuRows->sum('hpp_ideal'); @endphp
            @forelse($menuRows as $m)
                <tr>
                    <td>{{ $m->menu->name ?? '—' }}</td>
                    <td class="r">{{ $n($m->total_sold) }}</td>
                    <td class="r">{{ $rp($m->hpp_per_pcs) }}</td>
                    <td class="r">{{ $rp($m->hpp_ideal) }}</td>
                    <td class="r">{{ $totIdeal > 0 ? $pct($m->hpp_ideal / $totIdeal * 100) : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="c muted">Tidak ada penjualan menu pada periode ini.</td></tr>
            @endforelse
        </tbody>
        @if($menuRows->isNotEmpty())
        <tfoot>
            <tr>
                <td>TOTAL</td>
                <td class="r">{{ $n($menuRows->sum('total_sold')) }}</td>
                <td></td>
                <td class="r">{{ $rp($totIdeal) }}</td>
                <td class="r">100%</td>
            </tr>
        </tfoot>
        @endif
    </table>

    {{-- ══ PER BAHAN ══ --}}
    <h2>Analisa per Bahan <span class="note">ideal = seharusnya menurut resep · aktual = kenyataan dari opname</span></h2>
    <table>
        <thead>
            <tr>
                <th style="width:24%">Bahan</th>
                <th class="r">Pemakaian Ideal</th>
                <th class="r">Pemakaian Aktual</th>
                <th class="r">Selisih</th>
                <th class="r">HPP Ideal</th>
                <th class="r">HPP Aktual</th>
                <th class="r">Selisih HPP</th>
            </tr>
        </thead>
        <tbody>
            @php $lastCat = null; @endphp
            @forelse($ingRows as $r)
                @php $cat = $r->ingredient->category ?: 'lainnya'; @endphp
                @if($cat !== $lastCat)
                    <tr><td class="cat" colspan="7">{{ strtoupper($cat) }}</td></tr>
                    @php $lastCat = $cat; @endphp
                @endif
                <tr>
                    <td>{{ $r->ingredient->name }}</td>
                    <td class="r">
                        {{ $n($r->ideal_base) }} {{ $r->ingredient->unit_base }}
                        @if($r->ideal_dus !== null)<br><span class="muted">{{ $n($r->ideal_dus, 2) }} dus</span>@endif
                    </td>
                    <td class="r">
                        @if($r->has_actual)
                            {{ $n($r->actual_base) }} {{ $r->ingredient->unit_base }}
                            @if($r->actual_dus !== null)<br><span class="muted">{{ $n($r->actual_dus, 2) }} dus</span>@endif
                        @else <span class="muted">—</span> @endif
                    </td>
                    <td class="r {{ $r->selisih_base === null ? '' : ($r->selisih_base >= 0 ? 'pos' : 'neg') }}">
                        {{ $r->selisih_base === null ? '—' : ($r->selisih_base >= 0 ? '+' : '−') . $n(abs($r->selisih_base)) }}
                    </td>
                    <td class="r">{{ $rp($r->hpp_ideal) }}</td>
                    <td class="r">{{ $r->has_actual ? $rp($r->hpp_aktual) : '—' }}</td>
                    <td class="r {{ $r->selisih_hpp === null ? '' : ($r->selisih_hpp >= 0 ? 'pos' : 'neg') }}">
                        {{ $r->selisih_hpp === null ? '—' : ($r->selisih_hpp >= 0 ? '+' : '−') . $rp(abs($r->selisih_hpp)) }}
                        @if($r->selisih_pct !== null)<br><span class="muted">{{ $pct($r->selisih_pct) }}</span>@endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="c muted">Tidak ada data pemakaian bahan.</td></tr>
            @endforelse
        </tbody>
        @if($ingRows->isNotEmpty())
        <tfoot>
            <tr>
                <td>TOTAL</td>
                <td></td><td></td><td></td>
                <td class="r">{{ $rp($ingRows->sum('hpp_ideal')) }}</td>
                <td class="r">{{ $summary->hpp_aktual === null ? '—' : $rp($summary->hpp_aktual) }}</td>
                <td class="r {{ $sel === null ? '' : ($sel >= 0 ? 'pos' : 'neg') }}">
                    {{ $sel === null ? '—' : ($sel >= 0 ? '+' : '−') . $rp(abs($sel)) }}
                </td>
            </tr>
        </tfoot>
        @endif
    </table>

    {{-- ══ CONE & CUP ══ --}}
    @if($coneCupRows->isNotEmpty())
    <h2>Rekonsiliasi Cone &amp; Cup <span class="note">Selisih = Rusak + Overfill + Tidak ada penjelasan</span></h2>
    <table>
        <thead>
            <tr>
                <th style="width:28%">Bahan</th>
                <th class="r">Terjual</th>
                <th class="r">Terpakai</th>
                <th class="r">Selisih</th>
                <th class="r">Rusak</th>
                <th class="r">Overfill</th>
                <th class="r">Tidak ada penjelasan</th>
            </tr>
        </thead>
        <tbody>
            @foreach($coneCupRows as $c)
                <tr>
                    <td>{{ $c->ingredient->name }}</td>
                    <td class="r">{{ $n($c->terjual) }}</td>
                    <td class="r">{{ $n($c->terpakai) }}</td>
                    <td class="r {{ $c->selisih > 0 ? 'pos' : ($c->selisih < 0 ? 'neg' : '') }}">
                        {{ $c->selisih > 0 ? '+' : '' }}{{ $n($c->selisih) }}
                    </td>
                    <td class="r">
                        {{ $n($c->rusak) }}
                        @if($c->is_override)<br><span class="muted">koreksi (waste: {{ $n($c->rusak_waste) }})</span>@endif
                    </td>
                    <td class="r">{{ $n($c->overfill) }}</td>
                    <td class="r {{ $c->unexplained > 0 ? 'pos' : ($c->unexplained < 0 ? 'neg' : '') }}">{{ $c->unexplained > 0 ? '+' : '' }}{{ $n($c->unexplained) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    {{-- ══ KETERANGAN ══ --}}
    <div class="legend">
        <b>Keterangan istilah</b><br>
        <b>HPP Ideal</b> — biaya bahan yang <i>seharusnya</i> terpakai menurut resep &times; jumlah terjual.<br>
        <b>HPP Aktual</b> — biaya bahan yang <i>benar-benar</i> terpakai, dihitung dari stok opname
        (stok awal + pembelian − stok akhir).<br>
        <b>Selisih HPP</b> — HPP Ideal − HPP Aktual. Bertanda <span class="pos">+ hijau</span> berarti pemakaian
        lebih hemat dari resep; <span class="neg">− merah</span> berarti lebih boros dan perlu ditelusuri.<br>
        <b>Tidak ada penjelasan</b> (Cone &amp; Cup) — selisih yang belum tertutup oleh catatan rusak maupun
        overfill; ini yang benar-benar perlu dicari penyebabnya.
    </div>

    <div class="ttd">
        <div>Dibuat oleh<div class="line">{{ auth()->user()->name }}</div></div>
        <div>Diperiksa oleh<div class="line">&nbsp;</div></div>
    </div>

</div>
</body>
</html>
