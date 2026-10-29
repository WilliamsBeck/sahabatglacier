<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Mengecilkan tabel audit_logs tanpa kehilangan log yang penting.
 *
 * Latar belakang (29 Okt 2026): audit_logs membengkak sampai 3,6 GB / 7,8 juta baris,
 * hampir semuanya catatan perubahan sisa batch FIFO (MutationItem.remaining_qty) yang
 * dibuat setiap kali stok dihitung ulang. Database melewati batas ukuran Hostinger,
 * izin INSERT/UPDATE/CREATE dicabut, dan seluruh input di Glacier error 500.
 * (Pencatatannya sendiri sudah dihentikan — lihat AuditObserver::$skipUpdateOnly.)
 *
 * Kenapa TRUNCATE, bukan DELETE: DELETE tidak mengecilkan file tabel InnoDB; untuk
 * itu tabel harus dibangun ulang, yang butuh izin yang sedang dicabut dan melewati
 * batas 120 detik per perintah di hosting. TRUNCATE cukup izin DROP dan selesai
 * seketika. Supaya log penting tidak ikut hilang, log itu disalin dulu ke file dan
 * dimasukkan kembali setelah izin tulis pulih.
 *
 *   php artisan audit:pangkas              → hitung saja (hanya baca)
 *   php artisan audit:pangkas --apply      → salin log penting ke file, cek, lalu kosongkan tabel
 *   php artisan audit:pangkas --pulihkan   → masukkan kembali log penting dari file terbaru
 *
 * File salinan: storage/app/audit-arsip/ (di luar public, tidak masuk git).
 */
class PangkasAuditLog extends Command
{
    protected $signature = 'audit:pangkas
        {--apply : salin log penting ke file, lalu kosongkan tabel}
        {--ya : lewati pertanyaan konfirmasi sebelum mengosongkan}
        {--pulihkan= : masukkan kembali log dari file salinan ("terakhir" = file terbaru)}
        {--tabel=audit_logs : nama tabel (hanya untuk uji)}';

    protected $description = 'Kecilkan audit_logs: buang log sisa stok FIFO, simpan & pulihkan log penting';

    // Rentang id per query. Tiap query jauh di bawah batas 120 detik hosting.
    private const POTONG = 50000;

    // Log yang HANYA berisi perubahan sisa batch FIFO — pembukuan turunan, aman dibuang.
    // COALESCE supaya baris tanpa new_values tidak jatuh jadi NULL (= ikut terbuang).
    private const SQL_LOG_FIFO = "(model = 'MutationItem' AND action = 'updated'"
        . " AND COALESCE(JSON_LENGTH(new_values), 0) = 1"
        . " AND COALESCE(JSON_CONTAINS_PATH(new_values, 'one', '$.remaining_qty'), 0) = 1)";

    public function handle(): int
    {
        $tabel = $this->option('tabel');
        if (!preg_match('/^[a-z_]+$/', $tabel)) { $this->error('Nama tabel tidak valid.'); return self::FAILURE; }

        if ($this->option('pulihkan') !== null) {
            return $this->pulihkan($tabel, (string) $this->option('pulihkan'));
        }

        $batas = DB::selectOne("SELECT MIN(id) AS a, MAX(id) AS b FROM `$tabel`");
        if ($batas->a === null) { $this->info("Tabel $tabel kosong."); return self::SUCCESS; }
        $this->info(sprintf('Tabel %s: %s MB, id %s s/d %s', $tabel, $this->ukuranMb($tabel),
            number_format($batas->a, 0, ',', '.'), number_format($batas->b, 0, ',', '.')));

        $apply = (bool) $this->option('apply');
        $file = null; $gz = null;
        if ($apply) {
            $folder = storage_path('app/audit-arsip');
            if (!is_dir($folder)) mkdir($folder, 0750, true);
            $file = $folder . '/' . $tabel . '_penting_' . now()->format('Ymd_His') . '.jsonl.gz';
            $gz = gzopen($file, 'wb6');
            $this->line('Menyalin log penting ke ' . $file);
        }

        $penting = 0; $fifo = 0;
        $bar = $this->output->createProgressBar((int) ceil(($batas->b - $batas->a + 1) / self::POTONG));
        for ($dari = (int) $batas->a; $dari <= (int) $batas->b; $dari += self::POTONG) {
            $sampai = $dari + self::POTONG - 1;
            $fifo += (int) DB::selectOne("SELECT COUNT(*) AS n FROM `$tabel` WHERE id BETWEEN ? AND ? AND " . self::SQL_LOG_FIFO,
                [$dari, $sampai])->n;
            $rows = DB::select("SELECT * FROM `$tabel` WHERE id BETWEEN ? AND ? AND NOT " . self::SQL_LOG_FIFO . ' ORDER BY id',
                [$dari, $sampai]);
            $penting += count($rows);
            if ($gz) {
                foreach ($rows as $r) gzwrite($gz, json_encode($r, JSON_UNESCAPED_UNICODE) . "\n");
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);
        $this->table(['Jenis log', 'Jumlah baris'], [
            ['Sisa stok FIFO (dibuang)', number_format($fifo, 0, ',', '.')],
            ['Penting (disimpan)', number_format($penting, 0, ',', '.')],
        ]);

        if (!$apply) {
            $this->info('Hanya hitung. Jalankan dengan --apply untuk menyalin log penting lalu mengosongkan tabel.');
            return self::SUCCESS;
        }
        gzclose($gz);

        // ── Cek salinan sebelum menghapus apa pun ──
        $baris = 0; $idTerakhir = null;
        $h = gzopen($file, 'rb');
        while (($l = gzgets($h)) !== false) {
            if (trim($l) === '') continue;
            $o = json_decode($l);
            if (!$o || !isset($o->id)) { gzclose($h); $this->error('File salinan rusak — tabel TIDAK dikosongkan.'); return self::FAILURE; }
            $baris++; $idTerakhir = $o->id;
        }
        gzclose($h);
        if ($baris !== $penting) {
            $this->error("Isi file ($baris) tidak sama dengan jumlah log penting ($penting) — tabel TIDAK dikosongkan.");
            return self::FAILURE;
        }
        // Ada log baru masuk selama penyalinan? Berarti salinan sudah tidak lengkap.
        if ((int) DB::table($tabel)->max('id') !== (int) $batas->b) {
            $this->error('Ada log baru masuk selama penyalinan — tabel TIDAK dikosongkan. Jalankan ulang.');
            return self::FAILURE;
        }
        $this->info("Salinan terverifikasi: $baris baris (" . round(filesize($file) / 1048576, 1) . ' MB terkompresi).');

        if (!$this->option('ya')
            && !$this->confirm("Kosongkan tabel $tabel sekarang? Log penting sudah aman di file salinan.", false)) {
            $this->warn('Dibatalkan. Tabel tidak diubah; file salinan tetap tersimpan.');
            return self::SUCCESS;
        }

        DB::statement("TRUNCATE TABLE `$tabel`");
        // TRUNCATE mengulang penomoran id dari 1. Lanjutkan dari id lama supaya log baru
        // tidak bertabrakan dengan id log penting yang nanti dimasukkan kembali.
        try {
            DB::statement("ALTER TABLE `$tabel` AUTO_INCREMENT = " . ((int) $batas->b + 1));
        } catch (\Throwable $e) {
            $this->warn('Penomoran id tidak bisa dilanjutkan (' . $e->getMessage() . '). Tidak masalah: saat dipulihkan, log lama diberi id baru.');
        }

        $this->info("Tabel $tabel dikosongkan (" . DB::table($tabel)->count() . ' baris tersisa).');
        $this->line('Setelah izin tulis database pulih, jalankan: php artisan audit:pangkas --pulihkan=terakhir');
        return self::SUCCESS;
    }

    private function pulihkan(string $tabel, string $path): int
    {
        if ($path === 'terakhir' || $path === '') {
            // File yang sudah pernah dipulihkan (berakhiran .dipulihkan) tidak ikut — cegah dobel.
            $semua = array_filter(glob(storage_path('app/audit-arsip/' . $tabel . '_penting_*.jsonl.gz')) ?: [],
                fn($f) => !str_ends_with($f, '.dipulihkan.jsonl.gz'));
            sort($semua);
            $path = end($semua) ?: '';
        }
        if (!$path || !is_file($path)) { $this->error('File salinan tidak ditemukan.'); return self::FAILURE; }
        $this->info('Memulihkan dari ' . $path);

        // Pertahankan id asli kalau tidak bertabrakan dengan log yang sudah ada. Log yang
        // masuk setelah pengosongan memakai id di atas salinan (AUTO_INCREMENT dilanjutkan),
        // jadi yang dicek hanya rentang id milik file salinan.
        $idMin = null; $idMax = null; $h = gzopen($path, 'rb');
        while (($l = gzgets($h)) !== false) {
            if (trim($l) === '') continue;
            $id = json_decode($l)->id ?? null;
            if ($idMin === null) $idMin = $id;
            $idMax = $id;
        }
        gzclose($h);
        $pakaiIdAsli = $idMin === null || !DB::table($tabel)->whereBetween('id', [$idMin, $idMax])->exists();
        if (!$pakaiIdAsli) $this->warn('Id lama sudah terpakai — log lama dimasukkan dengan id baru.');

        $n = 0; $paket = [];
        $kirim = function () use (&$paket, &$n, $tabel, $pakaiIdAsli) {
            if (!$paket) return;
            // insertOrIgnore saat id asli dipakai: aman dijalankan ulang kalau sempat terputus.
            $pakaiIdAsli ? DB::table($tabel)->insertOrIgnore($paket) : DB::table($tabel)->insert($paket);
            $n += count($paket); $paket = [];
        };
        $h = gzopen($path, 'rb');
        try {
            while (($l = gzgets($h)) !== false) {
                if (trim($l) === '') continue;
                $r = json_decode($l, true);
                if (!$pakaiIdAsli) unset($r['id']);
                $paket[] = $r;
                if (count($paket) >= 500) $kirim();
            }
            $kirim();
        } catch (\Throwable $e) {
            gzclose($h);
            $this->error("Gagal setelah $n baris: " . $e->getMessage());
            $this->line('Kalau pesannya "INSERT command denied", izin tulis belum pulih — tunggu / hubungi Hostinger, lalu jalankan ulang.');
            return self::FAILURE;
        }
        gzclose($h);

        rename($path, preg_replace('/\.jsonl\.gz$/', '.dipulihkan.jsonl.gz', $path));
        $this->info("Selesai: $n log penting dimasukkan kembali.");
        return self::SUCCESS;
    }

    private function ukuranMb(string $tabel): string
    {
        $r = DB::selectOne('SELECT ROUND((data_length + index_length) / 1048576) AS mb FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = ?', [$tabel]);
        return number_format((float) ($r->mb ?? 0), 0, ',', '.');
    }
}
