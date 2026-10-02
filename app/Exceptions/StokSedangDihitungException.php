<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Stok satu bahan sedang dihitung ulang oleh proses lain terlalu lama, sehingga
 * kunci FIFO tidak didapat (lihat FifoService::withLock).
 *
 * Bukan kerusakan data: aksi yang memicunya dibatalkan seluruhnya (transaksi),
 * jadi aman diulang. Dijawab sebagai HTTP 409 berisi pesan yang bisa dibaca
 * operator — lihat bootstrap/app.php.
 */
class StokSedangDihitungException extends RuntimeException
{
}
