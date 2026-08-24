<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HppSnapshot extends Model
{
    protected $fillable = [
        'store_id', 'month', 'year', 'period_type',
        'omset', 'hpp_ideal', 'hpp_aktual', 'payload', 'locked_by',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function lockedBy()
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    /**
     * Apakah tanggal $date di toko $storeId sudah terkunci oleh snapshot HPP?
     * end_month → seluruh bulan terkunci; mid_month → hanya tgl 1–15.
     */
    public static function isDateLocked(int $storeId, string $date): bool
    {
        $c = \Carbon\Carbon::parse($date);
        $snaps = static::where('store_id', $storeId)
            ->where('month', $c->month)->where('year', $c->year)->get(['period_type']);
        foreach ($snaps as $s) {
            if ($s->period_type === 'end_month') return true;
            if ($s->period_type === 'mid_month' && $c->day <= 15) return true;
        }
        return false;
    }

    /**
     * Apakah periode opname (bulan/tahun + tipe) sudah terkunci HPP?
     */
    public static function isPeriodLocked(int $storeId, int $month, int $year, ?string $periodType = null): bool
    {
        $q = static::where('store_id', $storeId)->where('month', $month)->where('year', $year);
        if ($periodType) $q->where('period_type', $periodType);
        return $q->exists();
    }

    public static function lockMessageFor(int $storeId, int $month, int $year): string
    {
        $bulan = \Carbon\Carbon::create($year, $month, 1)->isoFormat('MMMM Y');
        return "HPP periode {$bulan} sudah dikunci. Opname & mutasi periode ini tidak bisa diubah. "
             . "Buka kunci HPP dulu di halaman Analisa HPP jika perlu koreksi.";
    }
}
