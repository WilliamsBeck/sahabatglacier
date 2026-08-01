<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonthlyRevenue extends Model
{
    protected $fillable = ['store_id', 'month', 'year', 'period_type', 'total_revenue', 'tiktok_diff', 'recorded_by'];

    public function store()      { return $this->belongsTo(Store::class); }
    public function recordedBy() { return $this->belongsTo(User::class, 'recorded_by'); }

    /**
     * Omset bruto = total - selisih TikTok.
     * Sengaja dihitung, bukan disimpan, supaya tidak ada dua angka yang bisa berbeda.
     */
    public function getGrossRevenueAttribute(): float
    {
        return (float) $this->total_revenue - (float) $this->tiktok_diff;
    }
}
