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

        // Blokir semua aksi tulis untuk akun hanya-lihat (role 'viewer') di seluruh web.
        $middleware->web(append: [
            RestrictViewer::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
