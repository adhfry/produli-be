<?php

use App\Http\Controllers\Api\V1\AnnouncementController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\KaderController;
use App\Http\Controllers\Api\V1\KecamatanController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PatientController;
use App\Http\Controllers\Api\V1\PuskesmasController;
use App\Http\Controllers\Api\V1\SilakesSyncController;
use App\Http\Controllers\Api\V1\StaffController;
use App\Http\Controllers\Api\V1\VisitAssignmentController;
use App\Http\Controllers\Api\V1\VisitReportController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:6,1');
    Route::post('refresh', [AuthController::class, 'refresh'])->middleware('throttle:15,1');
    Route::post('google/exchange', [AuthController::class, 'exchangeGoogle'])->middleware('throttle:15,1');
    // Publik (belum login) -- token dari email aktivasi, throttle jaga-jaga brute force token.
    Route::post('activate', [AuthController::class, 'activate'])->middleware('throttle:10,1');
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:6,1');
    Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:6,1');

    // Auth wajib TAPI SENGAJA TIDAK digerbangi password.changed -- ini kebutuhan dasar/jalan
    // keluar walau must_change_password=true (docs/planning/02, siklus password): user tetap
    // bisa tahu profilnya sendiri, logout, dan yang terpenting ganti password di sini.
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::post('change-password', [AuthController::class, 'changePassword']);
    });

    // Jalan keluar dari gerbang onboarding.completed (docs/planning/02 §14) -- SENGAJA masih
    // digerbangi password.changed (onboarding baru masuk akal setelah password wajib diganti
    // tuntas dulu), tapi TIDAK digerbangi onboarding.completed sendiri (kalau digerbangi,
    // user tidak akan pernah bisa memanggil endpoint yang justru menyelesaikan gerbang itu).
    Route::middleware(['auth:sanctum', 'password.changed'])->group(function () {
        Route::post('onboarding/complete', [AuthController::class, 'completeOnboarding']);
    });

    // Resend aktivasi & tautkan/lepas Google BUKAN kebutuhan darurat -- tetap digerbangi
    // password.changed DAN onboarding.completed seperti endpoint lain.
    Route::middleware(['auth:sanctum', 'password.changed', 'onboarding.completed'])->group(function () {
        Route::post('activate/resend', [AuthController::class, 'resendActivation']);

        // Balikin JSON {redirect_url} (bukan RedirectResponse langsung) -- endpoint ini butuh
        // Bearer token, sementara navigasi browser langsung tidak bisa bawa header itu; frontend
        // yang melakukan window.location = redirect_url dari sini.
        Route::get('google/link/redirect', [AuthController::class, 'googleLinkRedirect']);
        Route::delete('google/unlink', [AuthController::class, 'unlinkGoogle']);

        // Profil Saya & Pengaturan (docs/planning/02 §17) -- SELALU profil milik sendiri.
        Route::post('profile/avatar', [AuthController::class, 'uploadAvatar']);
        Route::patch('profile', [AuthController::class, 'updateProfile']);
    });
});

// Jalan keluar TAMBAHAN dari gerbang onboarding.completed (docs/planning/02 §14), sama pola
// dengan /auth/onboarding/complete di atas -- /onboarding (frontend) butuh DUA endpoint baca-saja
// ini utk menampilkan unit kerja & PJ Prolanis SUNGGUHAN di langkah "Konfirmasi Penempatan",
// SEBELUM onboarding_completed_at terisi. Kalau ikut digerbangi onboarding.completed, keduanya
// jadi mustahil dipanggil justru saat paling dibutuhkan (bug nyata -- user baru selalu lihat "-"
// dan pesan error ONBOARDING_REQUIRED di langkah ini). Read-only, tidak ada data sensitif per-
// pasien, aman diakses sebelum onboarding tuntas.
Route::middleware(['auth:sanctum', 'password.changed'])->group(function () {
    Route::get('puskesmas/{puskesmas}', [PuskesmasController::class, 'show']);
    Route::get('kader/profile', [KaderController::class, 'showProfile']);
});

Route::middleware(['auth:sanctum', 'password.changed', 'onboarding.completed'])->group(function () {
    Route::get('patients', [PatientController::class, 'index']);
    // SEBELUM 'patients/{patient}' -- kalau tidak, route-model-binding mencoba mencocokkan
    // 'search-nik' sebagai {patient} id (404 model not found), bukan route terpisah ini.
    Route::post('patients/search-nik', [PatientController::class, 'searchByNik']);
    Route::get('patients/{patient}', [PatientController::class, 'show']);
    // Usulan koreksi data pasien dari STAF (docs/planning §17 "Ajukan Update Data") -- paralel
    // dari usulan kader lewat POST /visit-reports (itu terikat satu laporan kunjungan).
    Route::patch('patients/{patient}/propose-update', [PatientController::class, 'proposeUpdate']);
    // Riwayat usulan (baca live dari SiLAKES, gerbang akses sama dengan propose-update di atas).
    Route::get('patients/{patient}/update-history', [PatientController::class, 'updateHistory']);

    Route::get('dashboard/summary', [DashboardController::class, 'summary']);

    Route::get('puskesmas', [PuskesmasController::class, 'index']);
    Route::patch('puskesmas/{puskesmas}', [PuskesmasController::class, 'update']);

    // Referensi kecamatan (id kanonik) -- dipakai filter /dashboard/pasien supaya tidak
    // bergantung ke kecamatan_raw teks bebas (docs: WilayahResolver). Data administratif
    // tetap, bukan data pasien -- semua role login boleh lihat, tanpa scope.
    Route::get('kecamatan', [KecamatanController::class, 'index']);

    // Tombol "Sinkronisasi SiLAKES" di sidebar -- super_admin saja (gerbang di controller,
    // route ini cuma butuh login+onboarding seperti endpoint lain di grup ini).
    Route::get('silakes/sync-status', [SilakesSyncController::class, 'status']);
    Route::post('silakes/sync', [SilakesSyncController::class, 'sync']);

    Route::prefix('kader')->group(function () {
        Route::get('/', [KaderController::class, 'index']);
        Route::post('/', [KaderController::class, 'store']);
        Route::patch('profile', [KaderController::class, 'updateProfile']);
        Route::get('pj-options', [KaderController::class, 'pjOptions']);
        // Self-service: riwayat pengajuan pembaruan data pasien milik kader sendiri (/app).
        Route::get('update-requests', [KaderController::class, 'updateRequests']);
        Route::patch('{kader}/status', [KaderController::class, 'setStatus']);
    });

    Route::get('visit-assignments', [VisitAssignmentController::class, 'index']);
    Route::post('visit-assignments', [VisitAssignmentController::class, 'store']);
    Route::post('visit-assignments/bulk', [VisitAssignmentController::class, 'bulkStore']);

    Route::post('visit-reports', [VisitReportController::class, 'store']);
    Route::patch('visit-reports/{visitReport}/accept', [VisitReportController::class, 'accept']);
    Route::patch('validasi-laporan/{visitReport}', [VisitReportController::class, 'validateReport']);

    Route::get('staff', [StaffController::class, 'index']);
    Route::post('staff', [StaffController::class, 'store']);

    Route::get('notifications', [NotificationController::class, 'index']);
    Route::patch('notifications/{id}/read', [NotificationController::class, 'markAsRead']);

    Route::get('announcements', [AnnouncementController::class, 'index']);
    Route::post('announcements', [AnnouncementController::class, 'store']);
});
