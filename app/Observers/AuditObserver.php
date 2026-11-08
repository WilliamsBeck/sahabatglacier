<?php
namespace App\Observers;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditObserver
{
    // Fields to always skip (timestamps, large binary etc.)
    // 'password': hash password tidak boleh ikut tersimpan di Log Aktivitas. Dulu ikut
    // tercatat saat akun dibuat/diubah; perubahan password kini dicatat terpisah tanpa
    // nilainya (UserController::resetPassword, Akun\PasswordController).
    private array $skipFields = ['updated_at', 'created_at', 'remember_token', 'password'];

    // Perubahan yang HANYA menyentuh kolom ini tidak dicatat sama sekali.
    // remaining_qty = sisa batch FIFO: berubah di puluhan batch setiap kali stok satu
    // bahan dihitung ulang (FifoService::decrement per potongan). Itu pembukuan turunan
    // yang selalu bisa dihitung ulang dari transaksinya, bukan aktivitas pengguna.
    // Dulu ikut dicatat dan membuat audit_logs membengkak sampai 3,6 GB / 7,8 juta baris,
    // sampai Hostinger mencabut izin tulis database (Okt 2026 — seluruh input error 500).
    // Perubahan lain pada model yang sama (mis. harga) tetap dicatat seperti biasa.
    private array $skipUpdateOnly = [
        'MutationItem' => ['remaining_qty'],
    ];

    public function created(Model $model): void
    {
        AuditLog::record(
            action: 'created',
            model: class_basename($model),
            modelId: $model->getKey(),
            description: $this->label($model, 'ditambahkan'),
            oldValues: null,
            newValues: $this->cleanValues($model->getAttributes()),
        );
    }

    public function updated(Model $model): void
    {
        $dirty = $model->getDirty();
        if (empty($dirty)) return;

        $abaikan = array_merge($this->skipFields, $this->skipUpdateOnly[class_basename($model)] ?? []);
        if (array_diff(array_keys($dirty), $abaikan) === []) return;

        $old = array_intersect_key($model->getOriginal(), $dirty);
        $new = $dirty;

        AuditLog::record(
            action: 'updated',
            model: class_basename($model),
            modelId: $model->getKey(),
            description: $this->label($model, 'diperbarui'),
            oldValues: $this->cleanValues($old),
            newValues: $this->cleanValues($new),
        );
    }

    public function deleted(Model $model): void
    {
        AuditLog::record(
            action: 'deleted',
            model: class_basename($model),
            modelId: $model->getKey(),
            description: $this->label($model, 'dihapus'),
            oldValues: $this->cleanValues($model->getAttributes()),
            newValues: null,
        );
    }

    // Nama model dalam Bahasa Indonesia untuk deskripsi yang ramah dibaca
    private array $modelLabels = [
        'Mutation' => 'Mutasi Stok', 'WasteLog' => 'Catatan Waste',
        'ProductionLog' => 'Produksi', 'Opname' => 'Stok Opname',
        'MonthlySale' => 'Penjualan Bulanan', 'MonthlyRevenue' => 'Omzet Bulanan',
        'Store' => 'Toko', 'Supplier' => 'Supplier', 'Ingredient' => 'Bahan Baku',
        'Menu' => 'Menu', 'Recipe' => 'Resep', 'User' => 'User',
        'IngredientCategory' => 'Kategori Bahan', 'MenuCategory' => 'Kategori Menu',
        'StoreStock' => 'Saldo Stok', 'HppMonthlyReport' => 'Laporan HPP',
        'DailyConfirmation' => 'Konfirmasi Harian',
    ];

    private function label(Model $model, string $verb): string
    {
        $base = class_basename($model);
        $name = $this->modelLabels[$base] ?? $base;

        // Mutasi: pakai label tipe transaksi (Pembelian Pusat, Pembelian Internal, dst.)
        if ($base === 'Mutation' && method_exists($model, 'getTypeLabelAttribute')) {
            $name = $model->type_label;
        }

        // Identitas ramah bila ada (tanpa #id teknis)
        $extra = '';
        foreach (['reference_no', 'invoice_no', 'name', 'title', 'code'] as $field) {
            if (!empty($model->$field)) {
                $extra = " — {$model->$field}";
                break;
            }
        }
        return "{$name}{$extra} {$verb}";
    }

    private function cleanValues(array $values): array
    {
        return array_filter($values, fn($k) => !in_array($k, $this->skipFields), ARRAY_FILTER_USE_KEY);
    }
}
