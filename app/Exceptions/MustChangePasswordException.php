<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * User wajib ganti password (users.must_change_password=true) sebelum mengakses endpoint lain
 * -- dilempar oleh EnsurePasswordChanged middleware. Pesan + kode di response (lihat
 * bootstrap/app.php) dipakai frontend buat redirect otomatis ke halaman ganti password.
 */
class MustChangePasswordException extends RuntimeException {}
