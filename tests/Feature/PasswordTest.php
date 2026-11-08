<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * PBI-105 — password sementara, wajib ganti password, menu Ganti Password,
 * dan Reset Password di Master User.
 */
class PasswordTest extends TestCase
{
    use RefreshDatabase;

    private Store $tokoA;
    private Store $tokoB;
    private User $superAdmin;
    private User $manager;
    private User $adminA;
    private User $adminB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tokoA = Store::forceCreate(['store_code' => '1', 'name' => 'Toko A', 'area' => 'Area 1']);
        $this->tokoB = Store::forceCreate(['store_code' => '2', 'name' => 'Toko B', 'area' => 'Area 2']);

        $this->superAdmin = User::factory()->superAdmin()->create();
        $this->manager    = User::factory()->manager()->create();
        $this->manager->stores()->attach($this->tokoA->id, ['assigned_at' => now()]);
        $this->adminA = User::factory()->adminArea()->create(['username' => 'admin_a', 'name' => 'Admin A']);
        $this->adminA->stores()->attach($this->tokoA->id, ['assigned_at' => now()]);
        $this->adminB = User::factory()->adminArea()->create(['username' => 'admin_b']);
        $this->adminB->stores()->attach($this->tokoB->id, ['assigned_at' => now()]);
    }

    // ── 1. Kolom & nilai otomatis ──────────────────────────────────────────

    public function test_kolom_ada_dan_akun_lama_tidak_dipaksa_ganti(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'wajib_ganti_password'));
        $this->assertFalse($this->adminA->fresh()->wajib_ganti_password);
    }

    public function test_akun_baru_otomatis_wajib_ganti_password(): void
    {
        $this->actingAs($this->superAdmin)->post(route('master.users.store'), [
            'name' => 'Baru', 'username' => 'user_baru', 'email' => 'baru@contoh.id',
            'password' => 'sementara123', 'password_confirmation' => 'sementara123',
            'role' => 'admin_area', 'store_ids' => [$this->tokoA->id],
        ])->assertSessionHasNoErrors();

        $this->assertTrue(User::where('username', 'user_baru')->value('wajib_ganti_password'));
    }

    // ── 2. Wajib ganti sebelum membuka halaman lain ────────────────────────

    public function test_akun_wajib_ganti_diarahkan_ke_halaman_ganti_password_setelah_login(): void
    {
        $u = User::factory()->wajibGantiPassword()->create(['username' => 'baru_login']);

        $this->post('/login', ['username' => 'baru_login', 'password' => 'password'])
            ->assertRedirect(route('akun.password.edit'));
    }

    public function test_akun_wajib_ganti_tidak_bisa_membuka_halaman_lain(): void
    {
        $u = User::factory()->wajibGantiPassword()->create();
        $u->stores()->attach($this->tokoA->id, ['assigned_at' => now()]);

        $this->actingAs($u)->get(route('dashboard'))->assertRedirect(route('akun.password.edit'));
        $this->actingAs($u)->get(route('inventory.stocks.index'))->assertRedirect(route('akun.password.edit'));
        $this->actingAs($u)->post(route('inventory.daily-ledger.confirm-date'), ['store_id' => $this->tokoA->id, 'date' => '2026-11-01'])
            ->assertRedirect(route('akun.password.edit'));
        $this->actingAs($u)->getJson(route('notifications.index'))->assertForbidden();

        // Halaman ganti password & logout tetap bisa
        $this->actingAs($u)->get(route('akun.password.edit'))->assertOk()->assertSee('password sementara');
        $this->actingAs($u)->post('/logout');
        $this->assertGuest();
    }

    public function test_setelah_ganti_password_kembali_ke_halaman_tujuan_dan_bebas_membuka_halaman(): void
    {
        $u = User::factory()->wajibGantiPassword()->create(['username' => 'baru_login']);

        $this->get(route('inventory.stocks.index'));                  // tujuan awal → diminta login
        $this->post('/login', ['username' => 'baru_login', 'password' => 'password']);
        $this->put(route('akun.password.update'), [
            'current_password' => 'password', 'password' => 'PilihanSaya99', 'password_confirmation' => 'PilihanSaya99',
        ])->assertRedirect(route('inventory.stocks.index'));

        $this->assertFalse($u->fresh()->wajib_ganti_password);
        // (Dashboard memakai fungsi MySQL DAY() — tidak bisa dirender di SQLite test.)
        $this->get(route('master.ingredients.index'))->assertOk();
    }

    // ── 3. Menu Ganti Password (semua jenis akun) ──────────────────────────

    public function test_ganti_password_berhasil_untuk_keempat_jenis_akun(): void
    {
        $spv = User::factory()->supervisor()->create();
        $spv->stores()->attach($this->tokoA->id, ['assigned_at' => now()]);

        foreach ([$this->superAdmin, $this->manager, $this->adminA, $spv] as $u) {
            // Menu "Ganti Password" ada di layout (dropdown akun) untuk semua jenis akun
            $this->actingAs($u)->get(route('master.ingredients.index'))
                ->assertOk()->assertSee(route('akun.password.edit'), false);
            $this->actingAs($u)->put(route('akun.password.update'), [
                'current_password' => 'password', 'password' => 'BaruSekali8', 'password_confirmation' => 'BaruSekali8',
            ])->assertSessionHasNoErrors()->assertSessionHas('success', 'Password berhasil diganti.');

            $this->assertTrue(Hash::check('BaruSekali8', $u->fresh()->password), $u->role . ' bisa ganti password');
        }
    }

    public function test_ganti_password_ditolak_bila_isian_salah(): void
    {
        $kirim = fn (array $d) => $this->actingAs($this->adminA)->put(route('akun.password.update'), $d);

        $kirim(['current_password' => 'keliru', 'password' => 'BaruSekali8', 'password_confirmation' => 'BaruSekali8'])
            ->assertSessionHasErrors(['current_password' => 'Password lama salah.']);
        $kirim(['current_password' => 'password', 'password' => 'pendek', 'password_confirmation' => 'pendek'])
            ->assertSessionHasErrors(['password' => 'Password baru minimal 8 karakter.']);
        $kirim(['current_password' => 'password', 'password' => 'BaruSekali8', 'password_confirmation' => 'BedaLagi88'])
            ->assertSessionHasErrors(['password' => 'Konfirmasi password baru tidak sama.']);

        $this->assertTrue(Hash::check('password', $this->adminA->fresh()->password), 'password lama tetap');
    }

    public function test_ganti_password_tercatat_tanpa_nilai_password(): void
    {
        $this->actingAs($this->adminA)->put(route('akun.password.update'), [
            'current_password' => 'password', 'password' => 'RahasiaBaru8', 'password_confirmation' => 'RahasiaBaru8',
        ]);

        $log = AuditLog::where('model', 'User')->where('model_id', $this->adminA->id)->latest('id')->first();
        $this->assertSame('Password diganti oleh pemilik akun: Admin A (admin_a)', $log->description);
        $this->assertTidakAdaPasswordDiLog('RahasiaBaru8');
    }

    // ── 4. Reset password di Master User ───────────────────────────────────

    public function test_form_edit_tidak_lagi_mengisi_password_langsung(): void
    {
        $this->actingAs($this->superAdmin)->get(route('master.users.edit', $this->adminA))
            ->assertOk()
            ->assertDontSee('name="password"', false)
            ->assertSee('Reset Password');

        // Isian password yang dikirim ke update diabaikan
        $this->actingAs($this->superAdmin)->put(route('master.users.update', $this->adminA), [
            'name' => $this->adminA->name, 'email' => $this->adminA->email, 'role' => 'admin_area',
            'password' => 'DisusupkanSaja', 'password_confirmation' => 'DisusupkanSaja',
        ])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('password', $this->adminA->fresh()->password));
    }

    public function test_reset_password_membuat_password_sementara_dan_menampilkannya_sekali(): void
    {
        $this->actingAs($this->superAdmin)->post(route('master.users.reset-password', $this->adminA))
            ->assertRedirect(route('master.users.edit', $this->adminA))
            ->assertSessionHas('password_sementara');

        $sementara = session('password_sementara')['password'];
        $this->assertSame(10, strlen($sementara));

        $u = $this->adminA->fresh();
        $this->assertTrue(Hash::check($sementara, $u->password), 'password sementara berlaku');
        $this->assertTrue($u->wajib_ganti_password, 'wajib diganti saat login');

        // Ditampilkan sekali: muncul di halaman berikutnya, lalu tidak lagi
        $this->get(route('master.users.edit', $this->adminA))->assertSee($sementara);
        $this->get(route('master.users.edit', $this->adminA))->assertDontSee($sementara);

        // Login dengan password sementara → langsung diminta ganti
        $this->post('/logout');
        $this->post('/login', ['username' => 'admin_a', 'password' => $sementara])
            ->assertRedirect(route('akun.password.edit'));
    }

    public function test_reset_password_tercatat_di_log_tanpa_nilai_password(): void
    {
        $this->actingAs($this->superAdmin)->post(route('master.users.reset-password', $this->adminA));
        $sementara = session('password_sementara')['password'];

        $log = AuditLog::where('model', 'User')->where('model_id', $this->adminA->id)->latest('id')->first();
        $this->assertSame($this->superAdmin->id, $log->user_id);
        $this->assertSame('Password direset untuk Admin A (admin_a) — wajib diganti saat login berikutnya', $log->description);
        $this->assertTidakAdaPasswordDiLog($sementara);
    }

    public function test_hash_password_tidak_lagi_ikut_tercatat_saat_akun_dibuat(): void
    {
        $this->actingAs($this->superAdmin)->post(route('master.users.store'), [
            'name' => 'Baru', 'username' => 'user_baru', 'email' => 'baru@contoh.id',
            'password' => 'sementara123', 'password_confirmation' => 'sementara123', 'role' => 'super_admin',
        ]);
        $hash = User::where('username', 'user_baru')->value('password');
        $this->assertTidakAdaPasswordDiLog($hash);
        $this->assertTidakAdaPasswordDiLog('sementara123');
    }

    public function test_akun_sendiri_tidak_direset_dari_master_user(): void
    {
        $this->actingAs($this->superAdmin)->post(route('master.users.reset-password', $this->superAdmin))
            ->assertSessionHas('error', 'Untuk akun Anda sendiri, gunakan menu Ganti Password.');
        $this->assertTrue(Hash::check('password', $this->superAdmin->fresh()->password));
    }

    // ── 5. Manager hanya akun di tokonya ───────────────────────────────────

    public function test_manager_hanya_bisa_reset_password_akun_di_tokonya(): void
    {
        $this->actingAs($this->manager)->post(route('master.users.reset-password', $this->adminA))
            ->assertSessionHas('password_sementara');
        $this->assertTrue($this->adminA->fresh()->wajib_ganti_password);

        $this->actingAs($this->manager)->post(route('master.users.reset-password', $this->adminB))->assertForbidden();
        $this->actingAs($this->manager)->post(route('master.users.reset-password', $this->superAdmin))->assertForbidden();
        $this->assertTrue(Hash::check('password', $this->adminB->fresh()->password));
        $this->assertFalse($this->adminB->fresh()->wajib_ganti_password);

        // Super admin bebas, admin area & supervisor tidak punya akses sama sekali
        $this->actingAs($this->superAdmin)->post(route('master.users.reset-password', $this->adminB))
            ->assertSessionHas('password_sementara');
        $this->actingAs($this->adminA)->post(route('master.users.reset-password', $this->adminB))->assertForbidden();
    }

    private function assertTidakAdaPasswordDiLog(string $nilai): void
    {
        foreach (AuditLog::all() as $log) {
            $isi = $log->description . json_encode($log->old_values) . json_encode($log->new_values);
            $this->assertStringNotContainsString($nilai, $isi, "log #{$log->id} memuat password");
        }
    }
}
