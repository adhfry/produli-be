<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * User belum menyelesaikan onboarding (users.onboarding_completed_at masih null) --
 * dilempar oleh EnsureOnboardingCompleted middleware. Pesan + kode di response (lihat
 * bootstrap/app.php) dipakai frontend buat redirect otomatis ke halaman /onboarding.
 */
class OnboardingRequiredException extends RuntimeException {}
