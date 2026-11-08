<?php
namespace App\Http\Controllers\MasterData;
use App\Http\Controllers\Controller;
use App\Models\{User, Store, Mutation, Opname, WasteLog, ProductionLog, StockLedger, AuditLog};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Master User.
 * - Super admin: mengelola semua akun & semua toko.
 * - Manager: hanya akun admin area & supervisor yang SEMUA tokonya ada di wilayah
 *   manager itu, dan hanya bisa memberi/mencabut toko miliknya sendiri. Akun yang
 *   juga memegang toko di luar wilayahnya tidak terlihat & tidak bisa diubah.
 * Route-nya sendiri hanya untuk super admin & manager (routes/web.php).
 */
class UserController extends Controller
{
    private const ROLE_UNTUK_MANAGER = ['admin_area', 'supervisor'];

    /** Jenis akun yang boleh dipilih user yang sedang login. */
    private function roleBoleh(): array
    {
        return auth()->user()->isSuperAdmin()
            ? array_keys(User::ROLES)
            : self::ROLE_UNTUK_MANAGER;
    }

    /** Semua id toko yang dipegang user yang sedang login (termasuk toko nonaktif). */
    private function tokoSaya(): array
    {
        $saya = auth()->user();
        return $saya->isSuperAdmin()
            ? Store::pluck('id')->all()
            : $saya->stores()->pluck('stores.id')->all();
    }

    /** Toko aktif yang bisa dipilih di form. */
    private function tokoPilihan()
    {
        return Store::where('is_active', true)
            ->whereIn('id', $this->tokoSaya())
            ->orderByRaw('CAST(store_code AS UNSIGNED)')
            ->get();
    }

    /**
     * Catat perubahan akses toko ke Log Aktivitas. Tabel pivot store_user tidak punya
     * model sehingga tidak tersentuh AuditObserver — tanpa ini, siapa memberi/mencabut
     * akses toko siapa tidak terlacak. Pelakunya dicatat otomatis oleh AuditLog::record.
     *
     * @param string $jenis 'ditambahkan' | 'dicabut'
     * @param int[]  $tokoIds toko yang berubah
     * @param int[]  $sebelum id toko user SEBELUM perubahan
     */
    private function catatAksesToko(User $user, string $jenis, array $tokoIds, array $sebelum): void
    {
        if (!$tokoIds) return;

        $nama     = Store::pluck('name', 'id');
        $daftar   = fn (array $ids) => collect($ids)->map(fn ($id) => $nama[$id] ?? "#$id")->sort()->implode(', ') ?: '(tidak ada)';
        $sesudah  = $jenis === 'ditambahkan'
            ? array_values(array_unique(array_merge($sebelum, $tokoIds)))
            : array_values(array_diff($sebelum, $tokoIds));
        $arah     = $jenis === 'ditambahkan' ? 'untuk' : 'dari';

        AuditLog::record(
            'updated', 'User', $user->id,
            "Akses toko {$jenis} {$arah} {$user->name} ({$user->username}): " . $daftar($tokoIds),
            ['akses_toko' => $daftar($sebelum)],
            ['akses_toko' => $daftar($sesudah)]
        );
    }

    /** Apakah akun $target boleh dilihat/diubah oleh user yang sedang login. */
    private function bolehDikelola(User $target): bool
    {
        $saya = auth()->user();
        if ($saya->isSuperAdmin()) return true;
        if (!$saya->isManager()) return false;
        if (!in_array($target->role, self::ROLE_UNTUK_MANAGER, true)) return false;

        $tokoTarget = $target->stores()->pluck('stores.id')->all();
        // Tanpa toko = tidak jelas wilayahnya → bukan urusan manager mana pun.
        return $tokoTarget && !array_diff($tokoTarget, $this->tokoSaya());
    }

    public function index(Request $request)
    {
        $query = User::with('stores')->latest();

        if (auth()->user()->isManager()) {
            $toko = $this->tokoSaya();
            $query->whereIn('role', self::ROLE_UNTUK_MANAGER)
                ->whereHas('stores')
                ->whereDoesntHave('stores', fn ($s) => $s->whereNotIn('stores.id', $toko));
        }
        if ($request->filled('search')) {
            $query->where(fn ($q) => $q->where('name', 'like', '%' . $request->search . '%')
                ->orWhere('email', 'like', '%' . $request->search . '%')
                ->orWhere('username', 'like', '%' . $request->search . '%'));
        }
        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        $users     = $query->paginate(20)->withQueryString();
        $roleBoleh = $this->roleBoleh();
        return view('master.users.index', compact('users', 'roleBoleh'));
    }

    public function create()
    {
        $stores    = $this->tokoPilihan();
        $roleBoleh = $this->roleBoleh();
        return view('master.users.form', compact('stores', 'roleBoleh'));
    }

    public function store(Request $request)
    {
        $tokoWajib = auth()->user()->isManager();
        $data = $request->validate([
            'name' => 'required|string',
            'username' => 'required|string|alpha_dash|unique:users,username',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:8|confirmed',
            'role' => ['required', Rule::in($this->roleBoleh())],
            // Manager wajib memilih minimal satu toko miliknya — akun tanpa toko tidak
            // bisa bekerja & tidak akan terlihat lagi oleh manager mana pun.
            'store_ids' => [$tokoWajib ? 'required' : 'nullable', 'array', $tokoWajib ? 'min:1' : 'min:0'],
            'store_ids.*' => [Rule::in($this->tokoSaya())],
        ], [
            'store_ids.required' => 'Pilih minimal satu toko untuk akun ini.',
            'store_ids.*.in'     => 'Ada toko yang di luar wilayah Anda.',
        ]);

        $storeIds = $data['store_ids'] ?? [];
        unset($data['store_ids']);
        $data['password'] = Hash::make($data['password']);
        // Password yang diketik pembuat akun = password SEMENTARA: wajib diganti pemiliknya
        // saat login pertama (PBI-105).
        $user = new User($data);
        $user->wajib_ganti_password = true;
        $user->save();
        if ($storeIds) {
            $storeIds = array_values(array_unique(array_map('intval', $storeIds)));
            $user->stores()->attach(array_fill_keys($storeIds, ['assigned_at' => now()]));
            $this->catatAksesToko($user, 'ditambahkan', $storeIds, []);
        }
        return redirect()->route('master.users.index')->with('success', 'User ditambahkan.');
    }

    public function edit(User $user)
    {
        abort_unless($this->bolehDikelola($user), 403, 'Anda tidak bisa mengelola akun ini.');
        $stores = $this->tokoPilihan();
        $user->load('stores');
        $assignedStores = $user->stores->pluck('id')->toArray();
        $roleBoleh = $this->roleBoleh();
        return view('master.users.form', compact('user', 'stores', 'assignedStores', 'roleBoleh'));
    }

    public function update(Request $request, User $user)
    {
        abort_unless($this->bolehDikelola($user), 403, 'Anda tidak bisa mengelola akun ini.');
        $data = $request->validate([
            'name' => 'required|string',
            'email' => 'required|email|unique:users,email,' . $user->id,
            'role' => ['required', Rule::in($this->roleBoleh())],
        ]);
        // Password TIDAK diubah dari form edit (PBI-105) — isian 'password' apa pun
        // diabaikan karena tidak divalidasi di atas. Pakai resetPassword().

        // Jenis akun SENDIRI tidak bisa diubah lewat form ini — mis. super admin yang
        // tanpa sengaja menjadikan dirinya manager langsung kehilangan akses ke toko lain,
        // Master Data global, dan tidak bisa mengembalikannya sendiri.
        if ($user->id === auth()->id() && $data['role'] !== $user->role) {
            return back()->withInput()->withErrors([
                'role' => 'Anda tidak bisa mengubah jenis akun Anda sendiri. Minta Super Admin lain untuk mengubahnya bila memang perlu.',
            ]);
        }

        $user->update($data);
        return redirect()->route('master.users.index')->with('success', 'User diupdate.');
    }

    /**
     * Reset password (PBI-105): buat password SEMENTARA, tampilkan sekali ke yang
     * mereset, dan wajibkan pemilik akun menggantinya saat login berikutnya.
     * Hak aksesnya sama dengan mengelola akun itu — manager hanya akun di tokonya.
     */
    public function resetPassword(User $user)
    {
        abort_unless($this->bolehDikelola($user), 403, 'Anda tidak bisa mengelola akun ini.');
        if ($user->id === auth()->id()) {
            return back()->with('error', 'Untuk akun Anda sendiri, gunakan menu Ganti Password.');
        }

        $sementara = $this->buatPasswordSementara();

        // saveQuietly: dicatat sendiri di bawah TANPA nilai password-nya. Remember token
        // diganti supaya sesi "Ingat saya" lama di perangkat lain ikut tidak berlaku.
        $user->forceFill(['password' => $sementara, 'wajib_ganti_password' => true])
            ->setRememberToken(Str::random(60));
        $user->saveQuietly();

        AuditLog::record('updated', 'User', $user->id,
            "Password direset untuk {$user->name} ({$user->username}) — wajib diganti saat login berikutnya",
            null, ['wajib_ganti_password' => true]);

        return redirect()->route('master.users.edit', $user)->with('password_sementara', [
            'nama' => $user->name, 'username' => $user->username, 'password' => $sementara,
        ]);
    }

    /**
     * 8 karakter (= syarat minimal password) tanpa huruf/angka yang mirip (0/O, 1/l/I)
     * — mudah didiktekan. Cukup karena sifatnya sementara: wajib diganti saat login,
     * dan tebak-tebakan dibatasi jeda 5x gagal di halaman login.
     */
    private function buatPasswordSementara(): string
    {
        $karakter = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $hasil = '';
        for ($i = 0; $i < 8; $i++) {
            $hasil .= $karakter[random_int(0, strlen($karakter) - 1)];
        }
        return $hasil;
    }

    public function destroy(User $user)
    {
        abort_unless($this->bolehDikelola($user), 403, 'Anda tidak bisa mengelola akun ini.');
        if ($user->id === auth()->id()) {
            return back()->with('error', 'Tidak bisa menghapus akun sendiri.');
        }

        $hasData = Mutation::where('created_by', $user->id)->exists()
            || Opname::where('performed_by', $user->id)->exists()
            || WasteLog::where('recorded_by', $user->id)->exists()
            || ProductionLog::where('created_by', $user->id)->exists()
            || StockLedger::where('created_by', $user->id)->exists();

        if ($hasData) {
            return back()->with('error', 'User tidak bisa dihapus karena sudah memiliki data transaksi. Pertimbangkan untuk menonaktifkan user.');
        }

        $user->stores()->detach();
        $user->delete();
        return redirect()->route('master.users.index')->with('success', 'User berhasil dihapus.');
    }

    public function assignStore(Request $request, User $user)
    {
        abort_unless($this->bolehDikelola($user), 403, 'Anda tidak bisa mengelola akun ini.');
        $request->validate([
            'store_ids'   => 'required|array',
            'store_ids.*' => [Rule::in($this->tokoSaya())],
        ], ['store_ids.*.in' => 'Ada toko yang di luar wilayah Anda.']);

        $existing = $user->stores()->pluck('stores.id')->map(fn ($id) => (int) $id)->all();
        $toAttach = array_values(array_diff(array_unique(array_map('intval', $request->store_ids)), $existing));
        if ($toAttach) {
            $user->stores()->attach(array_fill_keys($toAttach, ['assigned_at' => now()]));
            $this->catatAksesToko($user, 'ditambahkan', $toAttach, $existing);
        }
        return back()->with('success', count($toAttach) . ' toko berhasil di-assign.');
    }

    public function revokeStore(User $user, Store $store)
    {
        abort_unless($this->bolehDikelola($user), 403, 'Anda tidak bisa mengelola akun ini.');
        abort_unless(in_array($store->id, $this->tokoSaya()), 403, 'Toko ini di luar wilayah Anda.');

        // Manager tidak boleh mencabut toko terakhir: akun tanpa toko tidak bisa bekerja
        // & lepas dari pengawasan manager mana pun (tidak terlihat lagi di daftarnya).
        if (auth()->user()->isManager() && $user->stores()->count() <= 1) {
            return back()->with('error', 'Toko terakhir tidak bisa dicabut. Hubungi Super Admin bila akun ini perlu dinonaktifkan.');
        }

        $sebelum = $user->stores()->pluck('stores.id')->map(fn ($id) => (int) $id)->all();
        if ($user->stores()->detach($store->id)) {
            $this->catatAksesToko($user, 'dicabut', [(int) $store->id], $sebelum);
        }
        return back()->with('success', 'Akses toko dicabut.');
    }
}
