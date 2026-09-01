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
        'signature_secret' => env('PRODULI_INTEGRATION_SECRET'),
        'signature_tolerance_seconds' => 300,
        // Secret HMAC yang SAMA dengan services.produli.nik_hash_secret di SiLAKES
        // (api-administrasi-labkesda) -- PRODULI TIDAK PERNAH menyimpan NIK asli (patients_cache
        // cuma punya nik_hash, SiLAKES sudah hash sebelum kirim, docs/planning/04). Kunci yang
        // sama ini cuma memungkinkan PRODULI meng-hash input pencarian lalu membandingkan
        // hash-vs-hash (exact match) -- TIDAK PERNAH bisa dipakai membalik hash jadi NIK asli.
        'nik_hash_secret' => env('PRODULI_NIK_HASH_SECRET'),

        // Cutoff bisnis PRODULI (bukan dari kontrak SiLAKES -- endpoint lab-results tidak sediakan
        // parameter tanggal, cuma delta since/cursor berbasis updated_at). Pasien Prolanis yang
        // TIDAK punya riwayat surat_hasil_labs final (completed+approved+is_kunjungan_prolanis)
        // pada/sesudah tanggal ini dianggap belum relevan untuk PRODULI -- dikeluarkan dari
        // patients_cache saat sync (lihat SyncSilakesService::syncPatients()).
        'lab_results_since' => env('PRODULI_LAB_RESULTS_SINCE', '2026-01-01'),
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
        'face_detection_enabled' => (bool) env('PRODULI_FACE_DETECTION_ENABLED', false),

        'gps' => [
            // Akurasi GPS device (radius kemungkinan error) maksimum yang masih diterima.
            'max_accuracy_meters' => (int) env('PRODULI_GPS_MAX_ACCURACY_METERS', 100),
            // Titik GPS tidak boleh lebih tua dari ini saat submit ONLINE (indikasi lokasi basi/
            // dipalsukan). Dinaikkan dari 300 -> 1800 detik (revisi Bu Kadis, laporan lapangan
            // nyata "Titik GPS Sudah Terlalu Lama") -- frontend sekarang mengikat gpsCapturedAt
            // ke momen FOTO diambil (bukan momen halaman dibuka), tapi kader tetap wajar butuh
            // beberapa menit mengisi form pemeriksaan klinis lengkap SETELAH foto sebelum submit.
            'max_age_seconds' => (int) env('PRODULI_GPS_MAX_AGE_SECONDS', 1800),
            // Threshold TERPISAH & jauh lebih longgar untuk submission OFFLINE (isOffline=true di
            // VisitValidationContext) -- kader/nakes yang menyimpan draft lalu baru online
            // berjam-jam kemudian bukan indikasi kebohongan lokasi, itu memang cara kerja mode
            // offline yang disengaja. Default 48 jam.
            'max_age_seconds_offline' => (int) env('PRODULI_GPS_MAX_AGE_SECONDS_OFFLINE', 172800),
        ],

        'geofence' => [
            // geo_status=verified (titik presisi dari kader/pasien) -> radius ketat.
            'radius_meters_verified' => (int) env('PRODULI_GEOFENCE_RADIUS_VERIFIED', 150),
            // geo_status=approximate (centroid desa, docs/planning/02 §2c) -> radius jauh lebih
            // lebar, karena titik pembanding sendiri cuma perkiraan area, bukan rumah presisi.
            'radius_meters_approximate' => (int) env('PRODULI_GEOFENCE_RADIUS_APPROXIMATE', 3000),
        ],

        'exif' => [
            // Timestamp EXIF foto tidak boleh lebih tua dari ini (indikasi foto lama/reuse).
            'max_age_seconds' => (int) env('PRODULI_EXIF_MAX_AGE_SECONDS', 300),
            // Toleransi jarak antara GPS di metadata EXIF foto vs GPS device saat submit.
            'gps_tolerance_meters' => (int) env('PRODULI_EXIF_GPS_TOLERANCE_METERS', 200),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Penjadwalan & Reminder Kader (docs/planning/02 §8)
    |--------------------------------------------------------------------------
    |
    | Dokumen tidak menyebutkan jam pasti H-1/hari-H -- dua nilai di bawah jam
    | perkiraan wajar (sore sebelum kunjungan, pagi hari-H), sengaja configurable.
    | Command produli:send-visit-reminders dijadwalkan twiceDaily() PERSIS di jam
    | yang sama (lihat routes/console.php) supaya reminder tidak lambat terkirim
    | sampai berjam-jam menunggu run berikutnya.
    |
    */
    'reminders' => [
        'h_minus_1_time' => env('PRODULI_REMINDER_H1_TIME', '16:00'),
        'same_day_time' => env('PRODULI_REMINDER_SAME_DAY_TIME', '06:00'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ekspor Laporan PDF (revisi Bu Kadis, Fase 5)
    |--------------------------------------------------------------------------
    |
    | dompdf SANGAT boros memori untuk tabel besar (bukan linear) -- diuji nyata:
    | 500 baris ~262MB peak, 1000 baris CRASH (kehabisan memory_limit 512M) di
    | dompdf/src/Cellmap.php. PatientController::exportPdf() sebelumnya TIDAK
    | membatasi jumlah baris sama sekali -- kalau operator lupa mempersempit
    | filter dulu, request langsung menarik SEMUA pasien sekaligus dan proses
    | PHP mati mendadak (500 kosong, TIDAK sempat tercatat di log karena
    | prosesnya crash duluan). 500 dipilih sebagai batas aman dengan margin
    | -- kalau terlampaui, endpoint menolak dengan pesan jelas (422) supaya
    | operator mempersempit filter dulu, bukan diam-diam crash.
    |
    */
    'reports' => [
        'pdf_export_max_rows' => (int) env('PRODULI_PDF_EXPORT_MAX_ROWS', 500),

        // Ekspor "Download Hasil" (tabel pasien + kolom DINAMIS per parameter pemeriksaan --
        // GDP/CHOLESTEROL/TRIGLISERIDA/dst, permintaan user 2026-09-01). dompdf makin boros
        // memori makin banyak KOLOM (bukan cuma baris, lihat catatan pdf_export_max_rows di
        // atas) -- tabel ini bisa punya jauh lebih banyak kolom daripada tabel pasien biasa,
        // jadi batas barisnya dihitung ADAPTIF: maxRows = max_cells / jumlah_kolom (lihat
        // PatientController::exportHasilPdf()), bukan angka baris tetap. 4000 sel dipilih
        // dengan margin di bawah titik aman terukur di pdf_export_max_rows (500 baris x ~10
        // kolom = 5000 sel ~262MB, masih jauh dari crash 512M) -- angka BUKAN diukur ulang
        // khusus untuk tabel ini (kolom pemeriksaannya beda struktur), jadi sengaja dibuat
        // sedikit lebih konservatif.
        'hasil_pdf_export_max_cells' => (int) env('PRODULI_HASIL_PDF_EXPORT_MAX_CELLS', 4000),
        // Headroom tambahan di ATAS memory_limit default (biasanya 512M) KHUSUS request ekspor
        // ini -- batas sel adaptif di atas sudah menahan kasus wajar, ini cuma jaring pengaman
        // kedua, BUKAN pengganti batas adaptif (memory_limit besar tidak menolong kalau
        // pemakaian memori dompdf sendiri tetap non-linear terhadap jumlah sel).
        'hasil_pdf_export_memory_limit' => env('PRODULI_HASIL_PDF_MEMORY_LIMIT', '768M'),

        // Ekspor Excel TIDAK lewat dompdf -- Maatwebsite\Excel WithChunkReading menulis baris
        // ke file bertahap per chunk, tidak pernah menahan SEMUA pasien+hasil lab di memori
        // sekaligus, jadi jauh lebih murah dan batasnya bisa lebih longgar daripada PDF. Tetap
        // dibatasi (bukan unlimited) supaya filter yang longgar tidak membuat request berjalan
        // berjam-jam tanpa gerbang sama sekali -- untuk data lebih besar dari ini, gunakan
        // beberapa kali unduh dengan filter yang lebih sempit (mis. per puskesmas/kecamatan).
        'hasil_excel_export_max_rows' => (int) env('PRODULI_HASIL_EXCEL_EXPORT_MAX_ROWS', 20000),
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
        'visit_photos_disk' => env('PRODULI_VISIT_PHOTOS_DISK', 's3'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Kadensi Kunjungan Berulang (revisi Bu Kadis)
    |--------------------------------------------------------------------------
    |
    | tenaga_kesehatan_days_berat dipakai HANYA kalau level risiko pasien SAAT INI
    | (bukan saat plan dibuat) = berat -- dibaca ulang tiap kali cron scan jalan,
    | lihat CareAssignmentCadenceService::cadenceDaysFor().
    |
    */
    'cadence' => [
        'kader_days' => (int) env('PRODULI_CADENCE_KADER_DAYS', 7),
        'tenaga_kesehatan_days' => (int) env('PRODULI_CADENCE_TK_DAYS', 30),
        'tenaga_kesehatan_days_berat' => (int) env('PRODULI_CADENCE_TK_DAYS_BERAT', 14),
    ],

    /*
    |--------------------------------------------------------------------------
    | Penjadwalan Kegiatan Prolanis (permintaan user)
    |--------------------------------------------------------------------------
    |
    | Jadwal periksa Prolanis berikutnya dihitung dari lab_results_cache.tanggal_periksa
    | TERBARU pasien (BUKAN created_at) + interval sesuai jenis_prolanis -- lihat
    | ProlanisScheduleService::computeScheduledDate().
    |
    */
    'prolanis_schedule' => [
        'dm_interval_months' => (int) env('PRODULI_PROLANIS_DM_INTERVAL_MONTHS', 3),
        'ht_interval_months' => (int) env('PRODULI_PROLANIS_HT_INTERVAL_MONTHS', 6),
        // H-berapa hari sebelum scheduled_date puskesmas diberi notifikasi (permintaan user
        // "H-1 minggu") -- 7 hari, dibuat konfigurabel spt reminder kunjungan yang sudah ada.
        'reminder_days_before' => (int) env('PRODULI_PROLANIS_REMINDER_DAYS_BEFORE', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Smart Early Detection (revisi Bu Kadis)
    |--------------------------------------------------------------------------
    |
    | Ambang proximity (0-1) untuk flag "mendekati Berat" pada parameter direct-classifier --
    | lihat RiskClassificationService::evaluateEarlyDetection().
    |
    */
    'early_detection' => [
        // 0.6 (bukan mis. 0.7) -- dipilih supaya SECARA MATEMATIS bisa tercapai oleh band
        // 'sedang' Creatinine yang sebenarnya (1.7-1.9, tier Berat mulai 2.0): proximity
        // maksimum yang bisa dicapai nilai 1.9 (batas atas sedang) cuma (1.9-1.7)/(2.0-1.7)
        // = 66.7% -- ambang 0.7 TIDAK PERNAH bisa terpicu sama sekali dengan angka rujukan
        // asli, ambang harus di bawah 66.7% supaya early detection benar-benar bisa flag.
        'proximity_threshold' => (float) env('PRODULI_EARLY_DETECTION_PROXIMITY_THRESHOLD', 0.6),

        // REVISI (kombo/BERAT_PARAMETERS, bukan Creatinine): sebelumnya "combo_breadth" cuma
        // menghitung JUMLAH parameter yang exceeded (mis. "4 dari 5") tanpa peduli SEBERAPA JAUH
        // masing-masing di atas nilai rujukannya -- pasien dengan 1 parameter jauh di atas +
        // 3 parameter yang CUMA SEDIKIT di atas ambang tetap ke-flag, padahal belum tentu
        // benar-benar "menuju Berat". Sekarang setiap parameter kombo yang exceeded dihitung
        // margin-nya sbg persentase DI ATAS nilai rujukan ((nilai-ambang)/ambang), dan combo
        // HANYA di-flag kalau (a) minimal combo_min_parameters parameter exceeded sekaligus
        // (bukan cuma 1 parameter nyasar tinggi), (b) SELURUH parameter combo_required_parameters
        // WAJIB ada di antara yang exceeded itu (keputusan klinis: GDP/LDL/Trigliserida dianggap
        // pemeriksaan terpenting, Cholesterol/Urea jadi pelengkap opsional -- bukan lagi "3 dari
        // 5 mana saja"), DAN (c) SELURUH parameter yang exceeded (bukan cuma rata-ratanya)
        // sama-sama >= combo_margin_threshold_percent di atas rujukan -- baru dianggap benar-benar
        // "trending ke Berat" sebagai satu kesatuan.
        //
        // combo_min_parameters TIDAK BOLEH diset ke 5 (jumlah total BERAT_PARAMETERS) -- kalau
        // seluruh 5 parameter exceeded bersamaan, determineLevel() sendiri sudah memvonis Berat
        // (bukan Sedang lagi), sehingga evaluateEarlyDetection() (yang cuma jalan utk matchedLevel
        // 'sedang') tidak akan pernah sempat dievaluasi -- nilai maksimum yang masih bisa
        // berfungsi secara matematis adalah 4. Kedua angka + daftar parameter wajib ini murni
        // keputusan kebijakan klinis, sengaja configurable via .env supaya bisa disetel ulang
        // operator tanpa deploy kode kalau ternyata kurang/kelewat ketat di lapangan.
        'combo_min_parameters' => (int) env('PRODULI_EARLY_DETECTION_COMBO_MIN_PARAMETERS', 3),
        'combo_margin_threshold_percent' => (float) env('PRODULI_EARLY_DETECTION_COMBO_MARGIN_THRESHOLD_PERCENT', 50),
        'combo_required_parameters' => array_filter(array_map('trim', explode(',', (string) env(
            'PRODULI_EARLY_DETECTION_COMBO_REQUIRED_PARAMETERS',
            'Gula Darah Puasa,LDL,Trigliserida'
        )))),
    ],

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Gateway (bot sendiri, revisi Bu Kadis)
    |--------------------------------------------------------------------------
    |
    | Kontrak: POST {base_url}/send/chat-message/{api_key} body {phone, message,
    | link_image}. Lihat App\Services\Notification\Channels\WhatsappReminderChannel.
    |
    */
    'whatsapp' => [
        'base_url' => env('WA_API_BASE_URL'),
        'api_key' => env('WA_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Realtime (produli-wss, Phoenix Channels -- repo & domain terpisah dari
    | wss.silakes karena channel di sana tidak ada autentikasi sama sekali)
    |--------------------------------------------------------------------------
    |
    | Dua secret TERPISAH, WAJIB sama persis dengan PRODULI_WSS_TOKEN_SECRET/
    | PRODULI_WSS_BROADCAST_SECRET di .env produli-wss (server yang sama). Lihat
    | App\Services\Realtime\WebsocketTokenService (penerbit token) & RealtimeBroadcastService
    | (pemanggil POST /internal/broadcast).
    |
    */
    'realtime' => [
        'base_url' => env('PRODULI_WSS_BASE_URL'),
        'token_secret' => env('WSS_TOKEN_SECRET'),
        'broadcast_secret' => env('WSS_BROADCAST_SECRET'),
        // Umur token socket -- pendek sengaja (bukan setara access token biasa), frontend
        // minta token baru tiap kali socket reconnect (lihat useRealtime.ts), bukan sekali
        // ambil dipakai berjam-jam.
        'token_ttl_seconds' => (int) env('PRODULI_WSS_TOKEN_TTL_SECONDS', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Geocoding (PuskesmasGeocodingService, cari-otomatis koordinat puskesmas)
    |--------------------------------------------------------------------------
    |
    | OpenStreetMap Nominatim, BUKAN Google -- proyek ini sengaja tidak pakai Google
    | Maps sama sekali (lihat NUXT_PUBLIC_TILE_SERVER_URL frontend). Kebijakan Nominatim
    | mewajibkan maksimal 1 request/detik -- rate_limit_delay_microseconds DEFAULT
    | sedikit di atas 1 detik untuk margin aman, di-override 0 di tests (env.testing)
    | supaya suite tidak ikut lambat menunggu delay sungguhan.
    |
    */
    'geocoding' => [
        'rate_limit_delay_microseconds' => (int) env('GEOCODING_RATE_LIMIT_DELAY_MICROSECONDS', 1_100_000),
    ],

];
