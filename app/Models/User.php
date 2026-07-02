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

    public function isAdminArea(): bool
    {
        return $this->role === 'admin_area';
    }

    // Akun hanya-lihat (demo/dosen): bisa lihat semua, tidak bisa input apa pun.
    public function isViewer(): bool
    {
        return $this->role === 'viewer';
    }

    // Ambil toko yang bisa diakses user ini
    public function accessibleStores()
    {
        if ($this->isSuperAdmin() || $this->isViewer()) {
            return Store::where('is_active', true)->get();
        }
        return $this->stores()->where('is_active', true)->get();
    }

    public function accessibleStoreIds(): array
    {
        return $this->accessibleStores()->pluck('id')->toArray();
    }
}
