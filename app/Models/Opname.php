<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Opname extends Model
{
    protected $fillable = [
        'store_id', 'opname_date', 'period_month', 'period_year',
        'period_type', 'status', 'performed_by', 'approved_by', 'notes', 'opname_mode',
    ];
    protected $casts = ['opname_date' => 'date'];
    public function store()       { return $this->belongsTo(Store::class); }
    public function performedBy() { return $this->belongsTo(User::class, 'performed_by'); }
    public function approvedBy()  { return $this->belongsTo(User::class, 'approved_by'); }
    public function items() { return $this->hasMany(OpnameItem::class); }

    /**
     * Tanggal opname approved TERBARU untuk sebuah toko (batas tutup periode).
     * Transaksi bertanggal <= ini dianggap periode tertutup.
     */
    public static function lockDateFor(int $storeId): ?string
    {
        // Berdasarkan PERIODE opname (bukan tanggal input): end_month → akhir bulan,
        // mid_month → tgl 15. Ambil tanggal terjauh dari semua opname approved.
        $max = null;
        foreach (static::where('store_id', $storeId)->where('status', 'approved')
                    ->get(['period_month', 'period_year', 'period_type']) as $o) {
            $d = $o->period_type === 'mid_month'
                ? \Carbon\Carbon::create($o->period_year, $o->period_month, 15)
                : \Carbon\Carbon::create($o->period_year, $o->period_month, 1)->endOfMonth();
            if (!$max || $d->gt($max)) $max = $d;
        }
        return $max?->toDateString();
    }

    /**
     * Apakah tanggal $date sudah tertutup oleh opname approved di toko $storeId.
     */
    public static function isDateLocked(int $storeId, string $date): bool
    {
        $lock = static::lockDateFor($storeId);
        return $lock !== null && \Carbon\Carbon::parse($date)->lte(\Carbon\Carbon::parse($lock));
    }

    /**
     * Pesan error standar bila periode tertutup.
     */
    public static function lockMessageFor(int $storeId): string
    {
        $lock = static::lockDateFor($storeId);
        $tgl  = $lock ? \Carbon\Carbon::parse($lock)->isoFormat('D MMMM Y') : '-';
        return "Periode sudah ditutup oleh Opname tanggal {$tgl}. "
             . "Transaksi pada/sebelum tanggal itu tidak bisa diinput/diubah. "
             . "Hapus opname tersebut dulu jika perlu koreksi.";
    }

    /**
     * Cek apakah toko perlu opname akhir bulan sebelumnya sebelum bisa input mutasi
     * di bulan $txDate. Return null = boleh lanjut, string = pesan error.
     *
     * Rule: jika toko sudah pernah punya opname approved, maka untuk mutasi
     * di bulan M tahun Y, harus ada opname end_month approved untuk bulan M-1.
     * Exception: toko belum pernah punya opname approved sama sekali (setup awal).
     */
    public static function missingPreviousOpname(int $storeId, string $txDate): ?string
    {
        $hasAny = static::where('store_id', $storeId)->where('status', 'approved')->exists();
        if (!$hasAny) return null; // setup awal, boleh

        $d        = \Carbon\Carbon::parse($txDate);
        // startOfMonth dulu supaya subMonth tidak overflow saat tanggal = 31
        // (mis. 31 Juli - 1 bulan = "31 Juni" yang tidak ada → overflow ke 1 Juli).
        $prevMonth = $d->copy()->startOfMonth()->subMonth();
        $month    = (int) $prevMonth->month;
        $year     = (int) $prevMonth->year;

        $exists = static::where('store_id', $storeId)
            ->where('status', 'approved')
            ->where('period_type', 'end_month')
            ->where('period_month', $month)
            ->where('period_year', $year)
            ->exists();

        if ($exists) return null;

        $store    = \App\Models\Store::find($storeId);
        $namaBulan = $prevMonth->isoFormat('MMMM Y');
        return "Belum ada Stok Opname akhir bulan {$namaBulan} yang di-approve untuk toko "
             . ($store?->name ?? "#{$storeId}") . ". "
             . "Approve opname {$namaBulan} terlebih dahulu sebelum input mutasi bulan "
             . $d->isoFormat('MMMM Y') . ".";
    }
}
