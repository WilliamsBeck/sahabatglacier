<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PBI-104 / PBI-027 — empat jenis akun: super_admin, manager, admin_area, supervisor.
 *
 * Skenario: dua toko, A dan B. Manager, admin area, dan supervisor hanya memegang
 * toko A; super admin otomatis semua toko. Akun "orangB" adalah admin area toko B,
 * dipakai untuk memastikan manager toko A tidak bisa menyentuh wilayah lain.
 */
class HakAksesEmpatJenisAkunTest extends TestCase
{
    use RefreshDatabase;

    private Store $tokoA;
    private Store $tokoB;
    private User $superAdmin;
    private User $manager;
    private User $adminArea;
    private User $supervisor;
    private User $orangB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tokoA = Store::forceCreate(['store_code' => '1', 'name' => 'Toko A', 'area' => 'Area 1']);
        $this->tokoB = Store::forceCreate(['store_code' => '2', 'name' => 'Toko B', 'area' => 'Area 2']);

        $this->superAdmin = User::factory()->superAdmin()->create();
        $this->manager    = $this->denganToko(User::factory()->manager()->create(), $this->tokoA);
        $this->adminArea  = $this->denganToko(User::factory()->adminArea()->create(), $this->tokoA);
        $this->supervisor = $this->denganToko(User::factory()->supervisor()->create(), $this->tokoA);
        $this->orangB     = $this->denganToko(User::factory()->adminArea()->create(), $this->tokoB);
    }

    private function denganToko(User $user, Store ...$toko): User
    {
        foreach ($toko as $t) $user->stores()->attach($t->id, ['assigned_at' => now()]);
        return $user;
    }

    // ── Model & migration ──────────────────────────────────────────────────

    public function test_helper_jenis_akun_dan_toko_yang_bisa_diakses(): void
    {
        $this->assertTrue($this->superAdmin->isSuperAdmin());
        $this->assertTrue($this->manager->isManager());
        $this->assertTrue($this->adminArea->isAdminArea());
        $this->assertTrue($this->supervisor->isSupervisor());

        $this->assertTrue($this->superAdmin->isSuperAdminOrManager());
        $this->assertTrue($this->manager->isSuperAdminOrManager());
        $this->assertFalse($this->adminArea->isSuperAdminOrManager());
        $this->assertFalse($this->supervisor->isSuperAdminOrManager());

        $this->assertEqualsCanonicalizing([$this->tokoA->id, $this->tokoB->id], $this->superAdmin->accessibleStoreIds());
        foreach ([$this->manager, $this->adminArea, $this->supervisor] as $u) {
            $this->assertSame([$this->tokoA->id], $u->accessibleStoreIds(), $u->role . ' hanya toko yang di-assign');
        }

        $this->assertSame('Manager', $this->manager->roleLabel());
        $this->assertSame('Supervisor', $this->supervisor->roleLabel());
    }

    public function test_migration_mengubah_viewer_jadi_supervisor_tanpa_menyentuh_super_admin(): void
    {
        $migration = require database_path('migrations/2026_11_08_000000_ubah_role_users_jadi_empat_jenis_akun.php');

        // Kembali ke skema lama (super_admin, admin_area, viewer), lalu buat akun viewer.
        $migration->down();
        $viewerId = DB::table('users')->insertGetId([
            'name' => 'Dosen Demo', 'username' => 'demo', 'email' => 'demo@contoh.id',
            'password' => 'x', 'role' => 'viewer',
        ]);

        $migration->up();

        $this->assertSame('supervisor', DB::table('users')->where('id', $viewerId)->value('role'));
        $this->assertSame('super_admin', DB::table('users')->where('id', $this->superAdmin->id)->value('role'));
        $this->assertSame(0, DB::table('users')->where('role', 'viewer')->count());
    }

    // ── Supervisor ─────────────────────────────────────────────────────────

    public function test_supervisor_bisa_melihat_tapi_semua_request_selain_get_ditolak(): void
    {
        $this->actingAs($this->supervisor)
            ->get(route('master.ingredients.index'))
            ->assertOk();

        $sebelum = User::count();
        $this->actingAs($this->supervisor)
            ->from(route('master.ingredients.index'))
            ->post(route('master.users.store'), [
                'name' => 'X', 'username' => 'xx', 'email' => 'x@x.id',
                'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
                'role' => 'admin_area', 'store_ids' => [$this->tokoA->id],
            ])
            ->assertRedirect(route('master.ingredients.index'))
            ->assertSessionHas('error');
        $this->assertSame($sebelum, User::count(), 'tidak ada akun yang terbuat');

        // Permintaan AJAX dijawab 403, bukan redirect
        $this->actingAs($this->supervisor)
            ->postJson(route('inventory.daily-ledger.confirm-date'), ['store_id' => $this->tokoA->id, 'date' => '2026-11-01'])
            ->assertForbidden();
        $this->assertSame(0, DB::table('daily_confirmations')->count());
    }

    public function test_supervisor_tetap_bisa_logout(): void
    {
        $this->actingAs($this->supervisor)->post('/logout');
        $this->assertGuest();
    }

    public function test_supervisor_tidak_bisa_membuka_log_aktivitas_dan_master_user(): void
    {
        $this->actingAs($this->supervisor)->get(route('audit.index'))->assertForbidden();
        $this->actingAs($this->supervisor)->get(route('master.users.index'))->assertForbidden();
    }

    public function test_supervisor_hanya_melihat_toko_yang_di_assign(): void
    {
        $this->actingAs($this->supervisor)
            ->get(route('inventory.stocks.index', ['store_id' => $this->tokoB->id]))
            ->assertForbidden();
    }

    public function test_tampilan_supervisor_dalam_mode_hanya_lihat(): void
    {
        $html = $this->actingAs($this->supervisor)->get(route('master.ingredients.index'))->assertOk()->getContent();
        $this->assertStringContainsString('class="mode-lihat"', $html);
        $this->assertStringNotContainsString(route('audit.index'), $html, 'menu Log Aktivitas tidak tampil');
        $this->assertStringNotContainsString(route('master.users.index'), $html, 'menu User tidak tampil');

        $htmlAdmin = $this->actingAs($this->adminArea)->get(route('master.ingredients.index'))->getContent();
        $this->assertStringNotContainsString('class="mode-lihat"', $htmlAdmin);
    }

    // ── Manager ────────────────────────────────────────────────────────────

    public function test_manager_bisa_membuka_log_aktivitas_hanya_log_user_di_tokonya(): void
    {
        AuditLog::forceCreate(['user_id' => $this->adminArea->id, 'user_name' => 'a', 'action' => 'created',
            'model' => 'Mutation', 'description' => 'Log dari toko A']);
        $logB = AuditLog::forceCreate(['user_id' => $this->orangB->id, 'user_name' => 'b', 'action' => 'created',
            'model' => 'Mutation', 'description' => 'Log dari toko B']);

        $this->actingAs($this->manager)->get(route('audit.index'))
            ->assertOk()
            ->assertSee('Log dari toko A')
            ->assertDontSee('Log dari toko B');

        $this->actingAs($this->manager)->get(route('audit.show', $logB))->assertForbidden();

        $this->actingAs($this->superAdmin)->get(route('audit.index'))
            ->assertOk()->assertSee('Log dari toko A')->assertSee('Log dari toko B');
    }

    public function test_manager_hanya_melihat_akun_admin_area_dan_supervisor_di_tokonya(): void
    {
        $this->actingAs($this->manager)->get(route('master.users.index'))
            ->assertOk()
            ->assertSee($this->adminArea->email)
            ->assertSee($this->supervisor->email)
            ->assertDontSee($this->orangB->email)
            ->assertDontSee($this->superAdmin->email);

        $this->actingAs($this->manager)->get(route('master.users.edit', $this->orangB))->assertForbidden();
        $this->actingAs($this->manager)->put(route('master.users.update', $this->orangB), [
            'name' => 'Diubah', 'email' => $this->orangB->email, 'role' => 'supervisor',
        ])->assertForbidden();
        $this->assertNotSame('Diubah', $this->orangB->fresh()->name);
    }

    public function test_manager_membuat_akun_hanya_admin_area_atau_supervisor_di_tokonya(): void
    {
        $data = fn (array $ubah) => array_merge([
            'name' => 'Baru', 'username' => 'baru' . uniqid(), 'email' => uniqid() . '@contoh.id',
            'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
            'role' => 'supervisor', 'store_ids' => [$this->tokoA->id],
        ], $ubah);

        // Sah: supervisor untuk toko A
        $this->actingAs($this->manager)->post(route('master.users.store'), $data(['email' => 'sah@contoh.id']))
            ->assertRedirect(route('master.users.index'));
        $baru = User::where('email', 'sah@contoh.id')->firstOrFail();
        $this->assertSame('supervisor', $baru->role);
        $this->assertSame([$this->tokoA->id], $baru->stores()->pluck('stores.id')->all());

        // Ditolak: jenis akun di atas wewenang manager
        foreach (['super_admin', 'manager'] as $role) {
            $this->actingAs($this->manager)->post(route('master.users.store'), $data(['role' => $role]))
                ->assertSessionHasErrors('role');
        }
        // Ditolak: toko di luar wilayah, atau tanpa toko
        $this->actingAs($this->manager)->post(route('master.users.store'), $data(['store_ids' => [$this->tokoB->id]]))
            ->assertSessionHasErrors('store_ids.0');
        $this->actingAs($this->manager)->post(route('master.users.store'), $data(['store_ids' => []]))
            ->assertSessionHasErrors('store_ids');
    }

    public function test_manager_tidak_bisa_memberi_toko_di_luar_wilayah_atau_mencabut_toko_terakhir(): void
    {
        $this->actingAs($this->manager)
            ->post(route('master.users.assign-store', $this->adminArea), ['store_ids' => [$this->tokoB->id]])
            ->assertSessionHasErrors('store_ids.0');

        $this->actingAs($this->manager)
            ->delete(route('master.users.revoke-store', [$this->adminArea, $this->tokoA]))
            ->assertSessionHas('error');
        $this->assertTrue($this->adminArea->stores()->where('stores.id', $this->tokoA->id)->exists());
    }

    public function test_manager_hanya_melihat_master_data_global(): void
    {
        $this->actingAs($this->manager)->get(route('master.stores.index'))->assertOk();
        $this->actingAs($this->manager)->get(route('master.stores.create'))->assertForbidden();
        $this->actingAs($this->manager)->post(route('master.stores.store'), [
            'store_code' => '9', 'name' => 'Toko Liar', 'area' => 'X',
        ])->assertForbidden();
        $this->assertDatabaseMissing('stores', ['name' => 'Toko Liar']);
    }

    public function test_manager_dibatasi_ke_toko_yang_di_assign(): void
    {
        $this->actingAs($this->manager)
            ->get(route('inventory.stocks.index', ['store_id' => $this->tokoB->id]))
            ->assertForbidden();
    }

    // ── Admin area ─────────────────────────────────────────────────────────

    public function test_admin_area_tidak_bisa_kelola_user_log_atau_data_global(): void
    {
        // Dulu terbuka lewat URL: admin area bisa membuat akun super admin.
        $this->actingAs($this->adminArea)->get(route('master.users.index'))->assertForbidden();
        $this->actingAs($this->adminArea)->post(route('master.users.store'), [
            'name' => 'Penyusup', 'username' => 'penyusup', 'email' => 'p@contoh.id',
            'password' => 'rahasia123', 'password_confirmation' => 'rahasia123', 'role' => 'super_admin',
        ])->assertForbidden();
        $this->assertDatabaseMissing('users', ['username' => 'penyusup']);

        $this->actingAs($this->adminArea)->get(route('audit.index'))->assertForbidden();
        $this->actingAs($this->adminArea)->get(route('master.suppliers.create'))->assertForbidden();
        $this->actingAs($this->adminArea)->get(route('master.ingredients.index'))->assertOk();
    }

    // ── Super admin ────────────────────────────────────────────────────────

    public function test_super_admin_mengelola_semua_akun_dan_semua_toko(): void
    {
        $this->actingAs($this->superAdmin)->get(route('master.users.index'))
            ->assertOk()->assertSee($this->orangB->email)->assertSee($this->manager->email);

        $this->actingAs($this->superAdmin)->post(route('master.users.store'), [
            'name' => 'Manager Baru', 'username' => 'mgrbaru', 'email' => 'mgr@contoh.id',
            'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
            'role' => 'manager', 'store_ids' => [$this->tokoA->id, $this->tokoB->id],
        ])->assertRedirect(route('master.users.index'));
        $this->assertSame('manager', User::where('username', 'mgrbaru')->value('role'));

        $this->actingAs($this->superAdmin)->get(route('master.stores.create'))->assertOk();
        $this->actingAs($this->superAdmin)->get(route('audit.index'))->assertOk();
    }

    public function test_form_user_memakai_nama_jenis_akun_bahasa_indonesia(): void
    {
        $this->actingAs($this->superAdmin)->get(route('master.users.create'))
            ->assertOk()
            ->assertSee('Jenis Akun')
            ->assertSee('Manager — menyetujui')
            ->assertSee('Supervisor — hanya melihat')
            ->assertDontSee('viewer');

        $this->actingAs($this->manager)->get(route('master.users.create'))
            ->assertOk()
            ->assertSee('Admin Area — input')
            ->assertSee('Supervisor — hanya melihat')
            ->assertDontSee('value="super_admin"', false)
            ->assertDontSee('value="manager"', false)
            ->assertDontSee($this->tokoB->name);
    }
}
