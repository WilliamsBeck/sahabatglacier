<?php

namespace App\Console\Commands;

use App\Models\{Mutation, Opname, Store, Ingredient};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Alat cek HANYA-BACA: seberapa besar dampak perubahan aturan tanggal pengakuan
 * stok keluar (dari "tanggal terima" menjadi "tanggal kirim").
 *
 * Tidak menulis apa pun ke database. Aman dijalankan di server produksi kapan saja,
 * termasuk SEBELUM kode barunya di-deploy — hasilnya sama karena yang dihitung
 * adalah data mutasinya, bukan perilaku kodenya.
 */
class CekDampakTransit extends Command
{
    protected $signature   = 'stok:cek-dampak-transit';
    protected $description = 'Cek (hanya-baca) dampak aturan barang keluar diakui saat DIKIRIM, bukan saat DITERIMA';

    public function handle(): int
    {
        $this->info('Cek dampak aturan tanggal pengakuan stok keluar — HANYA MEMBACA, tidak mengubah data.');
        $this->newLine();

        $dasar = Mutation::where('status', 'confirmed')
            ->whereIn('type', ['sale_internal', 'sale_external_out']);

        $total = (clone $dasar)->count();
        $kosong = (clone $dasar)->whereNull('delivery_date')->count();
        $beda = (clone $dasar)->whereNotNull('delivery_date')
            ->whereColumn('delivery_date', '!=', 'transaction_date')->count();
        $lintasBulan = (clone $dasar)->whereNotNull('delivery_date')
            ->whereRaw('DATE_FORMAT(delivery_date, "%Y-%m") <> DATE_FORMAT(transaction_date, "%Y-%m")')
            ->count();

        $this->table(['Pemeriksaan', 'Jumlah'], [
            ['Transfer/penjualan keluar confirmed', $total],
            ['  tanggal terima kosong',             $kosong],
            ['  tanggal kirim != tanggal terima',   $beda],
            ['  beda BULAN (yang menggeser angka)', $lintasBulan],
        ]);

        if ($lintasBulan === 0) {
            $this->newLine();
            $this->info('AMAN — tidak ada satu pun transfer yang tanggal kirim & terimanya beda bulan.');
            $this->line('Artinya angka historis (stok akhir bulan, HPP, opname) TIDAK akan bergeser sama sekali.');
            if ($beda > 0) {
                $this->line("Catatan: ada {$beda} transfer yang tanggalnya beda tapi masih dalam bulan yang sama —");
                $this->line('itu hanya menggeser angka HARIAN di Pencatatan Harian, total bulanannya tetap.');
            }
            return $this->cekKunci($dasar);
        }

        $this->newLine();
        $this->warn("PERHATIAN — {$lintasBulan} transfer beda bulan. Angka bulanan akan bergeser di toko PENGIRIM.");
        $this->line('Rinciannya di bawah: baris "keluar" pindah dari bulan terima ke bulan kirim.');
        $this->newLine();

        $rows = (clone $dasar)->whereNotNull('delivery_date')
            ->whereRaw('DATE_FORMAT(delivery_date, "%Y-%m") <> DATE_FORMAT(transaction_date, "%Y-%m")')
            ->with(['sourceStore:id,name', 'destinationStore:id,name', 'items:id,mutation_id,ingredient_id,total_in_base'])
            ->orderBy('transaction_date')
            ->get();

        $tabel = [];
        foreach ($rows as $m) {
            $tabel[] = [
                $m->reference_no,
                $m->sourceStore->name ?? '-',
                $m->destinationStore->name ?? '-',
                $m->transaction_date->toDateString(),
                $m->delivery_date->toDateString(),
                $m->items->count() . ' bahan',
                number_format($m->items->sum('total_in_base'), 0, ',', '.'),
            ];
        }
        $this->table(['Ref', 'Dari', 'Ke', 'Kirim', 'Terima', 'Isi', 'Total base'], $tabel);

        // Ringkasan pergeseran per toko pengirim & bulan
        $this->newLine();
        $this->line('Pergeseran total "barang keluar" per toko pengirim & bulan:');
        $geser = [];
        foreach ($rows as $m) {
            $jml   = (float) $m->items->sum('total_in_base');
            $toko  = $m->sourceStore->name ?? ('toko #' . $m->source_store_id);
            $dari  = $m->delivery_date->format('Y-m');      // bulan lama
            $ke    = $m->transaction_date->format('Y-m');   // bulan baru
            $geser[$toko][$dari]['keluar'] = ($geser[$toko][$dari]['keluar'] ?? 0) - $jml;
            $geser[$toko][$ke]['keluar']   = ($geser[$toko][$ke]['keluar']   ?? 0) + $jml;
        }
        $t2 = [];
        foreach ($geser as $toko => $bulanan) {
            ksort($bulanan);
            foreach ($bulanan as $bln => $v) {
                $d = $v['keluar'];
                // Bulan yang saling menutup (masuk & keluar sama besar) tidak berubah
                // sama sekali — jangan ditampilkan supaya tidak salah dibaca.
                if (abs($d) < 0.001) continue;
                $t2[] = [$toko, $bln,
                    ($d > 0 ? '+' : '') . number_format($d, 0, ',', '.'),
                    $d > 0 ? 'keluar BERTAMBAH -> stok akhir TURUN'
                           : 'keluar berkurang -> stok akhir NAIK'];
            }
        }

        if (empty($t2)) {
            $this->info('Ternyata saling menutup — tidak ada bulan yang totalnya berubah.');
        } else {
            $this->table(['Toko pengirim', 'Bulan', 'Perubahan barang keluar (base)', 'Efek'], $t2);
            $this->newLine();
            $this->warn('Bulan-bulan di atas yang stok akhirnya akan berubah. Kalau ada yang sudah dikunci');
            $this->warn('opname/HPP, laporkan dulu sebelum deploy.');
        }

        return $this->cekKunci($dasar);
    }

    /** Transfer yang tanggal KIRIM-nya jatuh di periode terkunci milik toko pengirim. */
    private function cekKunci($dasar): int
    {
        $this->newLine();
        $this->line('Cek tambahan: transfer yang status kunci periodenya BERUBAH karena aturan baru');
        $this->line('(dulu dinilai dari tanggal terima, sekarang dari tanggal kirim).');

        // Yang tanggal kirim = tanggal terima tidak mungkin berubah statusnya —
        // dulu & sekarang dinilai di tanggal yang sama. Jadi tidak perlu diperiksa.
        $kandidat = (clone $dasar)->whereNotNull('source_store_id')
            ->whereNotNull('delivery_date')
            ->whereColumn('delivery_date', '!=', 'transaction_date')
            ->with('sourceStore:id,name')
            ->get(['id', 'reference_no', 'source_store_id', 'transaction_date', 'delivery_date']);

        $kena = [];
        foreach ($kandidat as $m) {
            $sid    = (int) $m->source_store_id;
            $kirim  = $m->transaction_date->toDateString();
            $terima = $m->delivery_date->toDateString();
            if (Opname::isDateLocked($sid, $kirim) && !Opname::isDateLocked($sid, $terima)) {
                $kena[] = [$m->reference_no, $m->sourceStore->name ?? '-', $kirim, $terima];
            }
        }

        if (empty($kena)) {
            $this->info('Tidak ada yang berubah status kuncinya. Aman.');
            return self::SUCCESS;
        }

        $this->warn(count($kena) . ' transfer yang sekarang dianggap berada di periode terkunci:');
        $this->table(['Ref', 'Toko pengirim', 'Kirim (dipakai skrg)', 'Terima (dulu dipakai)'], $kena);
        $this->line('Ini TIDAK merusak apa pun yang sudah tersimpan — hanya berarti transfer tersebut');
        $this->line('tidak bisa dibatalkan/dikonfirmasi ulang tanpa membuka kunci periodenya dulu.');

        return self::SUCCESS;
    }
}
