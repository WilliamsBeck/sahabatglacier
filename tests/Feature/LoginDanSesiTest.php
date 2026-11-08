<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * PBI-025 — login & sesi yang jelas.
 */
class LoginDanSesiTest extends TestCase
{
    use RefreshDatabase;

    private const PESAN_SALAH = 'Username/email atau password salah.';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['username' => 'kasir_alfa', 'email' => 'kasir@contoh.id']);
    }

    private function login(string $login, string $password = 'password', array $tambahan = [])
    {
        return $this->post('/login', array_merge(['username' => $login, 'password' => $password], $tambahan));
    }

    // 1. Username ATAU email
    public function test_bisa_login_dengan_username(): void
    {
        $this->login('kasir_alfa')->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($this->user);
    }

    public function test_bisa_login_dengan_email(): void
    {
        $this->login('kasir@contoh.id')->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($this->user);
    }

    // 2. Pesan salah tidak menyebut mana yang salah
    public function test_pesan_salah_sama_untuk_username_dan_password_yang_salah(): void
    {
        $this->login('kasir_alfa', 'salah-total')->assertSessionHasErrors(['username' => self::PESAN_SALAH]);
        $this->login('tidak_ada_orangnya')->assertSessionHasErrors(['username' => self::PESAN_SALAH]);
        $this->login('tidakada@contoh.id')->assertSessionHasErrors(['username' => self::PESAN_SALAH]);
        $this->assertGuest();
    }

    // 3. Jeda setelah 5 kali gagal dari IP yang sama
    public function test_login_dijeda_satu_menit_setelah_lima_kali_gagal(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->login('kasir_alfa', "salah-$i")->assertSessionHasErrors(['username' => self::PESAN_SALAH]);
        }

        // Percobaan ke-6 ditolak walau password BENAR
        $this->login('kasir_alfa')->assertSessionHasErrors('username');
        $this->assertStringContainsString('Terlalu banyak percobaan login', session('errors')->first('username'));
        $this->assertGuest();

        // Setelah satu menit, bisa login lagi
        $this->travel(61)->seconds();
        $this->login('kasir_alfa')->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($this->user);
    }

    public function test_jeda_dihitung_per_ip(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->login('kasir_alfa', "salah-$i");
        }
        // IP lain tidak ikut dijeda
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])->login('kasir_alfa')
            ->assertRedirect(route('dashboard', absolute: false));
    }

    // 4. Kembali ke halaman yang tadi dibuka
    public function test_setelah_login_kembali_ke_halaman_yang_tadi_dibuka(): void
    {
        $this->get(route('akun.password.edit'))->assertRedirect(route('login'));
        $this->login('kasir_alfa')->assertRedirect(route('akun.password.edit'));
    }

    // 5. Sesi habis saat menyimpan form (419)
    public function test_sesi_habis_diarahkan_ke_login_dengan_pesan(): void
    {
        Route::middleware('web')->post('/_uji-sesi-habis', fn () => throw new TokenMismatchException());

        $this->from('/inventory/stocks')->post('/_uji-sesi-habis')
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'Sesi berakhir, silakan login lagi.');

        // Setelah login, kembali ke halaman tempat form tadi disimpan
        $this->login('kasir_alfa')->assertRedirect('/inventory/stocks');

        $this->get(route('login'))->assertDontSee('Sesi berakhir'); // pesan hanya sekali
    }

    public function test_sesi_habis_pada_permintaan_ajax_dijawab_json(): void
    {
        Route::middleware('web')->post('/_uji-sesi-habis', fn () => throw new TokenMismatchException());

        $this->postJson('/_uji-sesi-habis')
            ->assertStatus(419)
            ->assertJson(['message' => 'Sesi berakhir, silakan login lagi.']);
    }

    public function test_pesan_sesi_berakhir_tampil_di_halaman_login(): void
    {
        $this->withSession(['status' => 'Sesi berakhir, silakan login lagi.'])
            ->get(route('login'))->assertOk()->assertSee('Sesi berakhir, silakan login lagi.');
    }

    // 6. Ingat saya
    public function test_ingat_saya_tetap_berfungsi(): void
    {
        $this->login('kasir_alfa', 'password', ['remember' => 'on'])
            ->assertCookie(Auth::guard()->getRecallerName());
        $this->assertNotEmpty($this->user->fresh()->remember_token);

        Auth::logout();
        $this->flushSession();
        $this->login('kasir_alfa')->assertCookieMissing(Auth::guard()->getRecallerName());
    }
}
