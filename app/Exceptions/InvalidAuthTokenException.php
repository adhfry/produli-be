<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Refresh token atau exchange code (Google login) tidak valid/kedaluwarsa/device
 * mismatch/terdeteksi reuse. Pesan spesifik ditampilkan ke client (beda dari
 * AuthenticationException bawaan yang di-generic-kan) — lihat bootstrap/app.php.
 */
class InvalidAuthTokenException extends RuntimeException {}
