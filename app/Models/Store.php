<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Store extends Model
{
    protected $fillable = [
        'store_code', 'name', 'area', 'is_active',
        'lead_time_days', 'order_cycle_days', 'safety_stock_days', 'par_days', 'dos_window_days',
    ];

    protected $casts = ['is_active' => 'boolean'];

    /**
     * Lead time dalam hari (waktu tunggu kiriman tiba).
     * Ini adalah REORDER POINT — saat DOS < lead_time, harus order sekarang.
     */
    public function leadTimeDays(): ?int
    {
        return $this->lead_time_days ? (int)$this->lead_time_days : null;
    }

    /**
     * Siklus order dalam hari (seberapa sering order, mis. setiap 15 hari).
     */
    public function orderCycleDays(): ?int
    {
        return $this->order_cycle_days ? (int)$this->order_cycle_days : null;
    }

    /**
     * Safety stock dalam hari (cadangan di atas lead time).
     * Reorder point = lead_time + safety_stock. NULL bila belum diset.
     */
    public function safetyStockDays(): ?int
    {
        return $this->safety_stock_days !== null ? (int)$this->safety_stock_days : null;
    }

    /**
     * Backward compat — par_days tetap ada tapi bukan dasar low stock alert.
     */
    public function parLevelDays(): ?int
    {
        return $this->par_days ? (int)$this->par_days : null;
    }

    /**
     * Window DOS dalam hari (7, 14, atau 30). Default 30.
     */
    public function dosWindowDays(): int
    {
        return in_array((int)$this->dos_window_days, [7, 14, 30])
            ? (int)$this->dos_window_days
            : 30;
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'store_user')->withPivot('assigned_at');
    }

    public function stocks()
    {
        return $this->hasMany(StoreStock::class);
    }

    public function mutations()
    {
        return $this->hasMany(Mutation::class, 'destination_store_id');
    }

    public function opnames()
    {
        return $this->hasMany(Opname::class);
    }

    public function wasteLogs()
    {
        return $this->hasMany(WasteLog::class);
    }

    public function productionLogs()
    {
        return $this->hasMany(ProductionLog::class);
    }
}
