<?php
namespace App\Services;

use App\Models\UnlockRequest;
use Carbon\Carbon;

class MonthLockService
{
    /**
     * Tanggal batas edit terakhir untuk bulan $month/$year.
     * Lock berlaku setelah tanggal ke-7 bulan berikutnya.
     * Contoh: data Mei 2026 → locked mulai 8 Juni 2026.
     */
    public static function lastEditDay(int $month, int $year): Carbon
    {
        return Carbon::create($year, $month, 1)->addMonth()->addDays(6);
    }

    /**
     * Cek apakah bulan ini sudah melewati batas edit.
     * DINONAKTIFKAN: month-lock dihapus — data boleh diedit kapan saja.
     */
    public static function isPastLock(int $month, int $year): bool
    {
        return false;
    }

    /**
     * Cek apakah sebuah resource tertentu masih terkunci
     * (melewati H+7 dan tidak ada approved unlock).
     *
     * Super admin selalu boleh (return false).
     *
     * @param  string  $resourceType  mutation | opname | monthly_sale
     * @param  int     $resourceId    ID record spesifik
     * @param  int     $month
     * @param  int     $year
     * @return bool  true = terkunci, false = boleh edit
     */
    public static function isLocked(string $resourceType, int $resourceId, int $month, int $year): bool
    {
        // Super admin & manager selalu boleh (manager tetap terbatas tokonya sendiri).
        if (auth()->user()->isSuperAdminOrManager()) return false;

        if (!self::isPastLock($month, $year)) return false;

        return !UnlockRequest::hasApprovedUnlock($resourceType, $resourceId);
    }

    /**
     * Pesan error lock standar untuk ditampilkan ke user.
     */
    public static function lockMessage(int $month, int $year): string
    {
        $label = Carbon::create($year, $month, 1)->isoFormat('MMMM Y');
        return "Data $label sudah terkunci (batas edit H+7). "
             . 'Ajukan request unlock ke Super Admin.';
    }
}
