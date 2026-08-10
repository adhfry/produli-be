<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Integrasi SiLAKES
    |--------------------------------------------------------------------------
    |
    | Kredensial ini diisi manual di .env oleh operator, bukan lewat AI agent
    | (lihat docs/planning/04-kontrak-api-silakes-aktual.md).
    |
    */
    'silakes' => [
        'base_url' => env('SILAKES_BASE_URL'),
        'token' => env('SILAKES_API_TOKEN'),
        // Ability token TERPISAH untuk endpoint tulis pembaruan-lapangan (integration:write-field-data)
        // — SENGAJA tidak reuse token baca di atas (docs/planning/01 §9).
        'write_token' => env('SILAKES_WRITE_API_TOKEN'),
        'signature_secret' => env('KOPIPU_INTEGRATION_SECRET'),
        'signature_tolerance_seconds' => 300,
        // Secret HMAC yang SAMA dengan services.kopipu.nik_hash_secret di SiLAKES
        // (api-administrasi-labkesda) -- KOPIPU TIDAK PERNAH menyimpan NIK asli (patients_cache
        // cuma punya nik_hash, SiLAKES sudah hash sebelum kirim, docs/planning/04). Kunci yang
        // sama ini cuma memungkinkan KOPIPU meng-hash input pencarian lalu membandingkan
        // hash-vs-hash (exact match) -- TIDAK PERNAH bisa dipakai membalik hash jadi NIK asli.
        'nik_hash_secret' => env('KOPIPU_NIK_HASH_SECRET'),

        // Cutoff bisnis KOPIPU (bukan dari kontrak SiLAKES -- endpoint lab-results tidak sediakan
        // parameter tanggal, cuma delta since/cursor berbasis updated_at). Pasien Prolanis yang
        // TIDAK punya riwayat surat_hasil_labs final (completed+approved+is_kunjungan_prolanis)
        // pada/sesudah tanggal ini dianggap belum relevan untuk KOPIPU -- dikeluarkan dari
        // patients_cache saat sync (lihat SyncSilakesService::syncPatients()).
        'lab_results_since' => env('KOPIPU_LAB_RESULTS_SINCE', '2026-01-01'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Autentikasi (docs/planning/02 §6)
    |--------------------------------------------------------------------------
    */
    'auth' => [
        // 'lax' cukup untuk Nuxt+Laravel di domain/subdomain sama (termasuk beda port
        // saat dev lokal). Kalau frontend production benar-benar cross-site, ganti ke
        // 'none' DAN pastikan APP_ENV bukan local/testing (cookie Secure wajib untuk SameSite=None).
        'refresh_cookie_samesite' => env('REFRESH_COOKIE_SAMESITE', 'lax'),
    ],

    /*
    |--------------------------------------------------------------------------
    | 7-Layer Visit Validation (docs/planning/02 §3/§10)
    |--------------------------------------------------------------------------
    |
    | Ambang batas di bawah ini TIDAK disebutkan angka pastinya di dokumen — nilai
    | default dipilih sebagai estimasi wajar untuk kader lapangan pakai HP, dan sengaja
    | dibuat configurable supaya bisa disetel ulang tanpa ubah kode kalau ternyata
    | kurang/kelewat ketat di lapangan.
    |
    */
    'validation' => [
        // Layer 6 — non-aktif default di v1 (docs/planning/02 §10). Deteksi wajah dilakukan
        // CLIENT-SIDE (browser), backend cuma percaya boolean presence yang dikirim — TIDAK
        // PERNAH menjalankan model deteksi sendiri atau menyimpan face embedding (UU PDP 27/2022).
        'face_detection_enabled' => (bool) env('KOPIPU_FACE_DETECTION_ENABLED', false),

        'gps' => [
            // Akurasi GPS device (radius kemungkinan error) maksimum yang masih diterima.
            'max_accuracy_meters' => (int) env('KOPIPU_GPS_MAX_ACCURACY_METERS', 100),
            // Titik GPS tidak boleh lebih tua dari ini saat submit (indikasi lokasi basi/dipalsukan).
            'max_age_seconds' => (int) env('KOPIPU_GPS_MAX_AGE_SECONDS', 300),
        ],

        'geofence' => [
            // geo_status=verified (titik presisi dari kader/pasien) -> radius ketat.
            'radius_meters_verified' => (int) env('KOPIPU_GEOFENCE_RADIUS_VERIFIED', 150),
            // geo_status=approximate (centroid desa, docs/planning/02 §2c) -> radius jauh lebih
            // lebar, karena titik pembanding sendiri cuma perkiraan area, bukan rumah presisi.
            'radius_meters_approximate' => (int) env('KOPIPU_GEOFENCE_RADIUS_APPROXIMATE', 3000),
        ],

        'exif' => [
            // Timestamp EXIF foto tidak boleh lebih tua dari ini (indikasi foto lama/reuse).
            'max_age_seconds' => (int) env('KOPIPU_EXIF_MAX_AGE_SECONDS', 300),
            // Toleransi jarak antara GPS di metadata EXIF foto vs GPS device saat submit.
            'gps_tolerance_meters' => (int) env('KOPIPU_EXIF_GPS_TOLERANCE_METERS', 200),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Penjadwalan & Reminder Kader (docs/planning/02 §8)
    |--------------------------------------------------------------------------
    |
    | Dokumen tidak menyebutkan jam pasti H-1/hari-H -- dua nilai di bawah jam
    | perkiraan wajar (sore sebelum kunjungan, pagi hari-H), sengaja configurable.
    | Command kopipu:send-visit-reminders dijadwalkan twiceDaily() PERSIS di jam
    | yang sama (lihat routes/console.php) supaya reminder tidak lambat terkirim
    | sampai berjam-jam menunggu run berikutnya.
    |
    */
    'reminders' => [
        'h_minus_1_time' => env('KOPIPU_REMINDER_H1_TIME', '16:00'),
        'same_day_time' => env('KOPIPU_REMINDER_SAME_DAY_TIME', '06:00'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Penyimpanan Foto Kunjungan (docs/planning/02 §5)
    |--------------------------------------------------------------------------
    |
    | Laravel yang push ke S3/MinIO (bukan Nuxt langsung) -- kredensial di disk
    | 's3' (config/filesystems.php, dari variabel AWS_ di .env) diisi manual oleh
    | operator. Disk terpisah di sini (bukan hardcode 's3' di kode) supaya bisa
    | dialihkan tanpa ubah kode kalau nanti pindah bucket/provider.
    |
    */
    'storage' => [
        'visit_photos_disk' => env('KOPIPU_VISIT_PHOTOS_DISK', 's3'),
    ],

];
