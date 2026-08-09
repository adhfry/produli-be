# Panduan Langkah Kerja — KOPIPU Smart

Urutan ini disusun berdasarkan dependensi: SiLAKES harus siap menyediakan data
dulu sebelum backend KOPIPU bisa sinkronisasi sungguhan, dan backend harus
punya endpoint dulu sebelum frontend bisa dites end-to-end.

**Aturan main di semua tahap:**

- 1 chat baru per repo di Claude Code (VS Code).
- Prompt pertama di tiap repo **selalu** sanity check (baca CLAUDE.md dulu).
- 1 prompt = 1 unit kerja fokus. Jangan gabung beberapa task besar dalam satu prompt — review dulu sebelum lanjut ke prompt berikutnya.

---

## TAHAP 0 — Sebelum Mulai: Cek Penempatan File

| File                                    | Rename jadi    | Taruh di                                                                 |
| --------------------------------------- | -------------- | ------------------------------------------------------------------------ |
| `CLAUDE-untuk-repo-silakes.md`          | `CLAUDE.md`    | root repo **SiLAKES**                                                    |
| `CLAUDE-untuk-repo-kopipu-backend.md`   | `CLAUDE.md`    | root repo **KOPIPU backend**                                             |
| `CLAUDE-untuk-repo-kopipu-frontend.md`  | `CLAUDE.md`    | root repo **KOPIPU frontend**                                            |
| `01-integrasi-silakes-kopipu.md`        | _(nama tetap)_ | `docs/planning/` di repo **SiLAKES** dan **KOPIPU backend** (dua-duanya) |
| `02-arsitektur-backend-kopipu-smart.md` | _(nama tetap)_ | `docs/planning/` di repo **KOPIPU backend**                              |
| `03-uiux-frontend-kopipu-nuxt.md`       | _(nama tetap)_ | `docs/planning/` di repo **KOPIPU frontend**                             |

---

## TAHAP 1 — Repo: SiLAKES ✅ SELESAI

_Kenapa duluan: backend KOPIPU butuh endpoint ini untuk sinkronisasi data asli. Tanpa ini, backend cuma bisa develop pakai data dummy._

1. Buka folder repo SiLAKES di VS Code → chat baru.
2. **Prompt 1 (sanity check):**
    > Baca CLAUDE.md dan docs/planning/01-integrasi-silakes-kopipu.md, lalu ringkas pemahamanmu soal proyek ini dan bagian yang jadi tanggung jawab repo ini.
3. **Prompt 2:**
    > Buatkan 3 endpoint read-only integrasi sesuai docs/planning/01: GET /api/v1/integration/patients, GET /api/v1/integration/lab-results (delta sync pakai cursor), GET /api/v1/integration/master-wilayah. Sertakan service-account user dengan Sanctum ability `integration:read-lab-results`, middleware throttle, dan verifikasi HMAC signature di header X-Signature.
4. Review kode, test manual (Postman/curl) dengan token dummy, baru commit.
5. **Prompt 3 (opsional):**
    > Tambahkan tabel dan logic `integration_sync_logs` untuk mencatat tiap request integrasi.
6. **Prompt 4 (BARU — aktif sekarang, bukan opsional lagi):**
    > Tambahkan field latitude/longitude ke response GET /api/v1/integration/master-wilayah sesuai docs/planning/04. Kolomnya sudah ada di tabel nusa (villages/districts/regencies/provinces), tinggal disertakan di response.
7. **Prompt 5 (BARU — unit kerja besar, sesi terpisah):**
    > Buatkan endpoint POST /api/v1/integration/patients/{id}/pembaruan-lapangan sesuai docs/planning/01 §9: tabel patient_field_updates (tunggal), ability token terpisah integration:write-field-data, semua usulan berstatus pending_review tanpa auto-apply.
8. **Prompt 6a — backend API persetujuan (Laravel):**
    > Buatkan endpoint API di Laravel sesuai docs/planning/01 §9: GET daftar usulan pending_review, POST approve (terapkan ke patients/patient_domiciles sesuai kategori field), POST reject (dengan catatan opsional). Format response standar SiLAKES seperti biasa. Belum perlu bikin apa pun di sisi Vue dulu.
9. **Prompt 6b — komponen Vue "Persetujuan Pembaruan Data Pasien" (frontend, sesi terpisah):**
    > Buatkan halaman/komponen Vue 3 yang mengonsumsi endpoint API dari Prompt 6a: daftar usulan pending_review, tampilkan nilai lama vs usulan berdampingan per field beserta sumber (nama kader, tanggal kunjungan), tombol Setujui/Tolak. Ikuti konvensi Vue yang sudah ada di project ini (routing, state management, gaya komponen admin lain) — jangan bikin pola baru.

---

## TAHAP 2 — Repo: KOPIPU Backend (Laravel)

_Bisa mulai scaffolding (migration, response format) sebelum Tahap 1 selesai — tapi SyncSilakesService baru bisa dites sungguhan setelah endpoint SiLAKES di Tahap 1 jadi._

**Keputusan scoping (hasil analisis data nyata):** pilot tetap di Gapura — 326 pasien Prolanis sudah punya kecamatan diketahui (189 di antaranya desa juga lengkap). Populasi ini otomatis jadi pool aktif begitu skema wilayah (Prompt 3 di bawah) jadi. Kampanye telepon Puskesmas/PJ untuk melengkapi sisa pasien (kecamatan+desa NULL) berjalan **paralel**, bukan syarat sebelum mulai — begitu datanya lengkap, pasien otomatis masuk pool tanpa perubahan sistem.

1. Buka folder repo KOPIPU backend → chat baru.
2. **Prompt 1 (sanity check):**
    > Baca CLAUDE.md dan docs/planning/01 dan 02, lalu ringkas pemahamanmu soal proyek ini dan bagian yang jadi tanggung jawab repo ini.
3. **Prompt 2 — fondasi response format (kerjakan lebih dulu, jadi basis semua endpoint berikutnya):**
    > Buatkan helper ApiResponse (success/error) dan override Exception Handler sesuai format di docs/planning/02 §4, supaya semua response termasuk error 422/401/500 otomatis terbungkus format ini.
4. **Prompt 3 — skema wilayah (BARU, prasyarat sebelum patients_cache karena ada FK ke desa):**
    > Buatkan migration untuk kabupaten, kecamatan, puskesmas, desa, wilayah_mapping sesuai docs/planning/02 §2a — termasuk seeder yang menarik data kecamatan/desa dari endpoint GET /api/v1/integration/master-wilayah di SiLAKES (docs/planning/04). Tambahkan command Artisan terpisah untuk bulk-import desa.puskesmas_id dari spreadsheet (Dinkes assign manual sekali di awal).
    > → Review, jalankan `php artisan migrate` + seeder, cek data kecamatan/desa Sumenep sudah masuk benar.
5. **Prompt 4 — migration inti sisanya:**
    > Buatkan migration untuk tabel patients_cache (dengan desa_id FK + wilayah_status: resolved/unresolved/unknown/out_of_scope), lab_results_cache, risk_thresholds, dan risk_classifications (tambahkan kolom is_latest boolean untuk hindari subquery MAX(computed_at) di query dashboard) sesuai docs/planning/02 §2.
6. **Prompt 5 — sinkronisasi + matching wilayah:**
    > Buatkan SyncSilakesService, RiskClassificationService, dan fungsi resolvePuskesmas() (fallback kecamatan-tunggal-puskesmas) sesuai docs/planning/02 §2a/§2b/§3 dan kontrak di docs/planning/04. Sertakan normalisasi teks (uppercase, buang karakter non-alfanumerik) dan deteksi out_of_scope untuk kecamatan di luar Sumenep.
    > → Tes setelah Tahap 1 selesai.
7. **Prompt 6 — autentikasi:**
    > Setup autentikasi Sanctum Bearer token + refresh token + device binding, dan login Google via Laravel Socialite, sesuai docs/planning/02 §6.
8. **Prompt 7 — role & permission:**
    > Setup Spatie Laravel-Permission dengan role super_admin, admin_puskesmas, pj_prolanis, kader, dan Policy class per scope data sesuai docs/planning/02 §7.
9. **Prompt 8 — kader & penugasan kunjungan:**
    > Buatkan migration `kader` (user_id, pj_id nullable, puskesmas_id, status_aktif, no_hp, no_wa, alamat, gender, tgl_lahir — sesuai docs/planning/02 §2/§7, email TIDAK diduplikasi dari users) dan `visit_assignments` (dengan puskesmas_id_snapshot) + VisitAssignmentService sesuai docs/planning/02 §2 dan §3. Validasi: tolak assignment untuk pasien dengan wilayah_status != resolved/kecamatan_fallback. Setelah ini, perhalus scoping `kader` di ScopesByPuskesmas/Policy (Prompt 7) dari level-puskesmas jadi level-assignment-pribadi sesuai gap yang sudah diketahui.
10. **Prompt 9 — validasi kunjungan (unit kerja besar, sesi terpisah):**
    > Buatkan VisitValidationService dengan Strategy Pattern untuk 7-layer validation sesuai docs/planning/02 §3 dan §10. FaceDetectionCheck non-aktif default di belakang feature-flag.
11. **Prompt 9b — laporan kunjungan, GIS, dan pembaruan data lapangan (lanjutan langsung, konteks masih nyambung):**
    > Buatkan migration visit_reports (dengan geo_status/geo_source/latitude/longitude sesuai docs/planning/02 §2c) dan VisitReportService: simpan laporan kunjungan lokal dulu (selalu berhasil meski SiLAKES down), lalu queue job terpisah (dengan retry) yang memanggil POST /api/v1/integration/patients/{id}/pembaruan-lapangan di SiLAKES. Panggilan ke SiLAKES tidak boleh membuat submit laporan kader gagal kalau gagal/timeout.
    > → Ini titik pertemuan paling sensitif antara dua repo — tes end-to-end setelah Tahap 1 Prompt 4 (endpoint SiLAKES) selesai, jangan cuma unit test.
12. **Prompt 10 — reminder:**
    > Buatkan migration reminders + NotificationService + scheduler job harian sesuai docs/planning/02 §8.

## TAHAP 2 LANJUTAN — Lapisan Controller/API HTTP (BARU — gap dari playbook awal)

_Prompt 1–10 membangun service layer, tapi belum ada Controller/route yang mengekspos ke HTTP. Ini wajib sebelum Tahap 3, karena Nuxt butuh endpoint nyata untuk dipanggil, bukan cuma service class internal._

13. **Prompt 11a — endpoint pasien & dashboard (read-heavy):**
    > Buatkan Controller + route untuk: GET /api/v1/patients (list pasien ter-scope Policy, dengan filter wilayah_status/risk level), GET /api/v1/patients/{id}, dan endpoint dashboard ringkas per role (jumlah pasien per level risiko, per status kunjungan) sesuai docs/planning/02 §7 untuk scope data. Pakai API Resource untuk shaping response, bukan return model mentah.
14. **Prompt 11b — endpoint kader:**
    > Buatkan Controller + route untuk: PJ mendaftarkan kader baru (no_hp wajib, field lain opsional sesuai docs/planning/02 §7), list kader milik PJ/puskesmas, dan endpoint self-service kader update profil sendiri (no_wa/alamat/gender/tgl_lahir) — kader cuma bisa edit profilnya sendiri, bukan punya orang lain.
15. **Prompt 11c — endpoint assignment & visit report:**
    > Buatkan Controller + route untuk: PJ membuat visit_assignment (pakai VisitAssignmentService yang sudah ada), list assignment (kader lihat tugasnya sendiri, PJ/admin_puskesmas lihat semua di puskesmasnya), dan endpoint submit visit_report (pakai VisitValidationService + VisitReportService yang sudah ada, termasuk upload foto — lihat docs/planning/02 §5 soal S3 di Laravel bukan Nuxt).
16. **Prompt 11d (BARU) — aktivasi akun via email untuk staf & kader:**
    > Bangun alur aktivasi akun via email untuk pendaftaran staf (admin_puskesmas/pj_prolanis) dan kader:
    >
    > 1. Migration `account_activations` (user_id, token_hash — pola sama seperti refresh_tokens.token_hash, expires_at default 7 hari, used_at, invited_by, created_at).
    > 2. Endpoint baru POST /api/v1/staff (super_admin only) — daftarkan admin_puskesmas/pj_prolanis baru (email+no_hp+puskesmas_id wajib), pola find-or-create User by email sama seperti kader (11b). User baru → buat AccountActivation + kirim email aktivasi. User existing → skip email.
    > 3. Retrofit POST /api/v1/kader (11b) supaya juga kirim email aktivasi saat User baru dibuat.
    > 4. Endpoint POST /api/v1/auth/activate {token} — validasi token, generate password random kriptografis, simpan hash ke users.password, tandai used_at, set flag wajib ganti password di login pertama. Response balikin password plaintext sekali saja.
    > 5. Endpoint POST /api/v1/auth/activate/resend (admin only) — invalidate token lama, generate baru, kirim ulang.
    > 6. Pengiriman email pakai Mailable/Notification standar, test pakai Mail::fake() — kredensial mail service asli diisi manual belakangan.
17. **Prompt 11e (BARU) — siklus password: enforcement & forgot password:**
    > Lengkapi siklus password: 1) Enforcement must_change_password — middleware yang menolak akses ke endpoint lain (kecuali change-password, /auth/me, /auth/logout) selagi flag ini true, kode error jelas untuk frontend redirect. Endpoint POST /api/v1/auth/change-password (password lama+baru, set must_change_password=false setelah berhasil). 2) Forgot password — pakai Password Broker bawaan Laravel (Password::sendResetLink()/Password::reset()), jangan bikin token infrastructure baru. Endpoint POST /api/v1/auth/forgot-password dan POST /api/v1/auth/reset-password. Setelah reset berhasil: must_change_password=false, revoke semua refresh_tokens user itu (force re-login semua device).
18. **Prompt 11f (BARU) — tautkan/lepas akun Google (setelah 11e):**
    > Bangun fitur tautkan/lepas akun Google untuk user yang sudah login via email/password: GET /api/v1/auth/google/link/redirect (authenticated, token acak di-cache 5 menit → user_id, kirim sebagai state ke Socialite), GET /auth/google/link/callback (publik, ambil state, tolak kalau google_id/email sudah terpasang ke user lain, baru set users.google_id kalau bersih), DELETE /api/v1/auth/google/unlink (authenticated, tolak kalau user tidak punya password sama sekali — cegah locked-out total).
19. **Prompt 11g (BARU) — endpoint notifications yang terlewat:**
    > Buatkan endpoint GET /api/v1/notifications (list notifikasi/reminder milik user yang login, dari tabel notifications bawaan Laravel via DatabaseReminderChannel, pagination + filter unread) dan PATCH /api/v1/notifications/{id}/read (tandai sudah dibaca) — tanpa ini reminder yang dibuat NotificationService (Prompt 10) tidak pernah bisa diambil frontend.

---

## TAHAP 3 — Repo: KOPIPU Frontend (Nuxt)

_Mulai setelah endpoint auth & data dasar di Tahap 2 siap, supaya bisa langsung dites terhubung ke backend asli (bukan mock)._

1. Buka folder repo KOPIPU frontend → chat baru.
2. **Prompt 1 (sanity check):**
    > Baca CLAUDE.md dan docs/planning/03, lalu ringkas pemahamanmu soal proyek ini dan bagian yang jadi tanggung jawab repo ini.
3. **Prompt 2 — struktur dasar:**
    > Setup struktur layouts/public.vue (SEO aktif) dan layouts/private.vue (noindex otomatis) sesuai docs/planning/03 §2 dan §5.
4. **Prompt 3 — state management:**
    > Buatkan Pinia store useAuthStore, useAssignmentStore, usePatientStore, useSyncStore beserta composable useApi sesuai docs/planning/03 §3.
5. **Prompt 4 — autentikasi:**
    > Buatkan halaman login email/password dan tombol login Google (redirect ke endpoint Laravel), terhubung ke useAuthStore.
6. **Prompt 5 — dashboard:**
    > Buatkan dashboard Dinkes/Puskesmas/PJ dengan skeleton loading dan pola stale-while-revalidate sesuai docs/planning/03 §4.
7. **Prompt 6 — aplikasi kader:**
    > Buatkan alur aplikasi kader (card-based, 1 aksi besar per layar) termasuk komponen GpsCameraCapture dan offline queue via IndexedDB sesuai docs/planning/03 §1, §4, §6.

---

## Setelah 3 Tahap Selesai

Baru masuk fase integrasi end-to-end: jalankan ketiga sistem bersamaan,
tes alur penuh dari sync SiLAKES → klasifikasi risiko → assignment kader →
laporan kunjungan → dashboard Dinkes.
