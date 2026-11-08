<?php
// bootstrap/app.php
// GANTI isi file ini dengan kode di bawah

use App\Http\Middleware\CheckRole;
use App\Http\Middleware\CheckStoreAccess;
use App\Http\Middleware\RestrictViewer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Daftarkan middleware alias di sini
        $middleware->alias([
            'role'         => CheckRole::class,
            'store.access' => CheckStoreAccess::class,
        ]);

        // Blokir semua aksi tulis untuk akun Supervisor (hanya-lihat) di seluruh web.
        $middleware->web(append: [
            // Password sementara wajib diganti dulu sebelum membuka halaman lain (PBI-105).
            \App\Http\Middleware\WajibGantiPassword::class,
            RestrictViewer::class,
            \App\Http\Middleware\RememberActiveStore::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Kunci FIFO tidak didapat → aksi sudah dibatalkan utuh (transaksi), aman diulang.
        // Jawab dengan pesan yang bisa dibaca operator, bukan "Server Error" generik.
        $exceptions->render(function (\App\Exceptions\StokSedangDihitungException $e, $request) {
            if ($request->expectsJson()) {
                return response()->json(['error' => $e->getMessage()], 409);
            }
            return back()->withInput()->with('error', $e->getMessage());
        });
        // Ini kondisi sementara yang wajar, bukan bug — tidak perlu memenuhi log error.
        $exceptions->dontReport(\App\Exceptions\StokSedangDihitungException::class);

        // Sesi habis saat menyimpan form (CSRF token kedaluwarsa → 419 "Page Expired").
        // Arahkan ke login dengan pesan yang jelas, bukan halaman 419 polos. Halaman
        // terakhir dicatat sebagai tujuan, jadi setelah login user kembali ke sana
        // (isian form-nya memang tidak bisa diselamatkan — sesinya sudah hilang).
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpException $e, $request) {
            if ($e->getStatusCode() !== 419) return null;
            $pesan = 'Sesi berakhir, silakan login lagi.';
            if ($request->expectsJson()) {
                return response()->json(['message' => $pesan], 419);
            }
            $sebelumnya = url()->previous();
            if ($sebelumnya && $sebelumnya !== $request->fullUrl() && !str_contains($sebelumnya, '/login')) {
                $request->session()->put('url.intended', $sebelumnya);
            }
            return redirect()->route('login')->with('status', $pesan);
        });
    })->create();
