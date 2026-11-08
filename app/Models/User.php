<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'username', 'email', 'password', 'role'];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = ['email_verified_at' => 'datetime', 'password' => 'hashed'];

    /**
     * Empat jenis akun (PBI-104 / PBI-027) beserta nama tampilannya.
     *  - super_admin : akses penuh, semua toko.
     *  - manager     : hak seperti super admin, tapi hanya di toko yang di-assign.
     *  - admin_area  : input & kelola transaksi di toko yang di-assign.
     *  - supervisor  : hanya melihat toko yang di-assign, tidak bisa mengubah data.
     */
    public const ROLES = [
        'super_admin' => 'Super Admin',
        'manager'     => 'Manager',
        'admin_area'  => 'Admin Area',
        'supervisor'  => 'Supervisor',
    ];

    // Relasi ke toko (many-to-many)
    public function stores()
    {
        return $this->belongsToMany(Store::class, 'store_user')->withPivot('assigned_at');
    }

    // Helper role
    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function isManager(): bool
    {
        return $this->role === 'manager';
    }

    public function isAdminArea(): bool
    {
        return $this->role === 'admin_area';
    }

    // Hanya-lihat: semua request selain GET ditolak (RestrictViewer), toko dibatasi assign.
    public function isSupervisor(): bool
    {
        return $this->role === 'supervisor';
    }

    /**
     * Hak "atasan": super admin, atau manager (yang tokonya sudah dibatasi lewat
     * accessibleStores / CheckStoreAccess). Dipakai di tempat yang dulu khusus
     * super admin tapi kini juga milik manager — mis. lewati kunci bulan, Log Aktivitas.
     */
    public function isSuperAdminOrManager(): bool
    {
        return $this->isSuperAdmin() || $this->isManager();
    }

    public function roleLabel(): string
    {
        return self::ROLES[$this->role] ?? ucfirst(str_replace('_', ' ', (string) $this->role));
    }

    // Ambil toko yang bisa diakses user ini. Hanya super admin yang melihat semua toko;
    // manager, admin area, dan supervisor terbatas pada toko yang di-assign.
    public function accessibleStores()
    {
        if ($this->isSuperAdmin()) {
            return Store::where('is_active', true)->get();
        }
        return $this->stores()->where('is_active', true)->get();
    }

    public function accessibleStoreIds(): array
    {
        return $this->accessibleStores()->pluck('id')->toArray();
    }
}
