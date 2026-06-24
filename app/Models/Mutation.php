<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Mutation extends Model
{
    protected $fillable = [
        'reference_no', 'type', 'source_store_id', 'destination_store_id',
        'supplier_id', 'external_sender', 'external_receiver', 'invoice_no', 'transaction_date', 'delivery_date',
        'status', 'notes', 'created_by', 'confirmed_by',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'delivery_date'    => 'date',
    ];

    // Auto-generate reference_no saat create
    protected static function booted(): void
    {
        static::creating(function ($mutation) {
            if (empty($mutation->reference_no)) {
                $mutation->reference_no = self::generateReferenceNo();
            }
        });
    }

    public static function generateReferenceNo(): string
    {
        $date   = now()->format('Ymd');
        $prefix = 'MUT-' . $date . '-';
        // Pakai nomor urut TERTINGGI yang ada + 1 (bukan count) supaya tidak bentrok
        // bila ada mutasi yang sudah dihapus (count berkurang → nomor lama dipakai lagi).
        $last = self::where('reference_no', 'like', $prefix . '%')
            ->orderByDesc('reference_no')->value('reference_no');
        $seq  = $last ? ((int) substr($last, -3)) + 1 : 1;
        return $prefix . str_pad($seq, 3, '0', STR_PAD_LEFT);
    }

    public function items()
    {
        return $this->hasMany(MutationItem::class);
    }

    public function sourceStore()
    {
        return $this->belongsTo(Store::class, 'source_store_id');
    }

    public function destinationStore()
    {
        return $this->belongsTo(Store::class, 'destination_store_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function confirmedBy()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function getTypeLabelAttribute(): string
    {
        return match ($this->type) {
            'purchase_zhisheng'  => 'Pembelian Pusat',
            'purchase_supplier'  => 'Pembelian Supplier Lokal',
            'opening_stock'      => 'Input Stok Awal',
            'sale_internal'      => 'Pembelian Internal',
            'sale_external'      => 'Pembelian Eksternal',
            'sale_external_out'  => 'Penjualan Eksternal',
            default              => $this->type,
        };
    }

    public function isPurchase(): bool
    {
        return in_array($this->type, ['purchase_zhisheng', 'purchase_supplier', 'opening_stock']);
    }

    public function isSale(): bool
    {
        return in_array($this->type, ['sale_internal', 'sale_external', 'sale_external_out']);
    }

    // Mutasi yang MEMOTONG stok toko sumber (barang keluar): transfer internal &
    // penjualan eksternal. (sale_external = barang MASUK, tidak memotong sumber.)
    public function deductsFromSource(): bool
    {
        return in_array($this->type, ['sale_internal', 'sale_external_out']);
    }
}
