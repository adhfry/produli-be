# Kontrak API SiLAKES (Aktual — Live)

> Dokumen ini adalah kontrak API **sebenarnya** dari 3 endpoint integrasi SiLAKES yang sudah diimplementasikan dan live. Lebih detail & mengikat daripada asumsi di `01-integrasi-silakes-produli.md` — kalau ada perbedaan, ikuti dokumen ini.
>
> ⚠️ Nilai secret di bawah **disensor**. Isi nilai asli langsung sebagai env var di PRODULI (`.env`), jangan pernah tulis nilai asli di kode, prompt ke AI, commit, atau dokumen manapun yang bisa ke-share.

Base URL: `{{SILAKES_BASE_URL}}`

Env var yang harus diisi manual di PRODULI (bukan lewat AI agent):

```
SILAKES_BASE_URL=
SILAKES_API_TOKEN=
PRODULI_INTEGRATION_SECRET=   # shared secret untuk HMAC signature request
```

Semua response: `{ "status": "success"|"error", "data": [...]|{...}, "message": "...", "meta"?: {...} }`

## Autentikasi (wajib tiap request)

1. `Authorization: Bearer {{SILAKES_API_TOKEN}}` — ability `integration:read-lab-results`.
2. `X-Signature` + `X-Timestamp` (HMAC-SHA256):
    - `timestamp` = unix timestamp detik saat request dibuat.
    - `signature = hash_hmac('sha256', <raw_body> + '.' + <timestamp>, PRODULI_INTEGRATION_SECRET)`.
    - GET tanpa body → `<raw_body>` = string kosong.
    - Server tolak jika selisih timestamp > 300 detik dari waktu server (anti-replay).
3. Rate limit: 60 req/menit → 429 jika kena limit.
4. Error codes: 401 (token/signature invalid), 403 (ability tidak cukup), 422 (query salah), 429 (rate limit).

## Endpoint 1 — `GET /api/v1/integration/patients`

Query (opsional): `since` (ISO8601), `cursor` (opaque, dari response sebelumnya), `per_page` (default 50, max 200).

```json
{
    "patient_id": 123,
    "no_reg": "REG-001",
    "name": "Budi Santoso",
    "nik_hash": "<HMAC-SHA256 hex, BUKAN NIK asli, BUKAN hash polos>",
    "gender": "L",
    "tgl_lahir": "1990-01-01",
    "phone": "081234567890",
    "alamat": "Jl. Test No. 1",
    "rt_rw": "001/002",
    "kel_desa": "Desa A", // teks bebas — BELUM kode wilayah baku
    "kecamatan": "Kecamatan A", // teks bebas — BELUM kode wilayah baku
    "is_prolanis": true,
    "jenis_prolanis": "...",
    "is_perokok": false,
    "jenis_perokok": null,
    "updated_at": "2026-07-31T10:00:00+00:00"
}
```

`meta: { "per_page": 50, "has_more": true, "next_cursor": "<opaque>|null" }`

**Wajib ditangani di PRODULI:** `kel_desa`/`kecamatan` perlu proses matching/normalisasi ke kode wilayah baku (lihat tabel `wilayah_mapping` di dokumen 02).

## Endpoint 2 — `GET /api/v1/integration/lab-results`

Query sama seperti di atas. Hanya mengembalikan hasil **FINAL** (`status=completed` DAN `status_konfirmasi=approved`).

**Direncanakan ditambahkan (belum live):** filter `is_kunjungan_prolanis=true` dipaksa di query — pasien `is_prolanis=1` bisa saja punya kunjungan lab untuk keperluan lain di luar rutin Prolanis; PRODULI cuma butuh kunjungan yang memang bagian dari pemeriksaan rutin Prolanis untuk klasifikasi risiko.

```json
{
    "lab_result_id": 456,
    "patient_id": 123,
    "tanggal": "2026-07-30",
    "status": "completed",
    "status_konfirmasi": "approved",
    "updated_at": "2026-07-30T09:05:00+00:00",
    "parameters": [
        {
            "parameter": "GDP",
            "satuan": "mg/dL",
            "nilai_rujukan": "70-110",
            "hasil": "95",
            "class_hasil": "Normal",
            "validation_status": "validated"
        }
    ]
}
```

**Penting:** `nilai_rujukan` = standar lab SiLAKES, **bukan** threshold risiko kesehatan PRODULI. Threshold PRODULI (GDP > 120, dst.) disimpan terpisah di `risk_thresholds` (dokumen 02).

## Endpoint 3 — `GET /api/v1/integration/master-wilayah`

Query: `level` (province|regency|district|village, wajib), `province_code`/`regency_code`/`district_code` (wajib sesuai level di atasnya).

```json
[
    {
        "code": "...",
        "name": "...",
        "<parent>_code": "...",
        "latitude": -7.0123,
        "longitude": 113.8456
    }
]
```

**Tidak ada level puskesmas** — puskesmas dikelola manual di PRODULI.

**Sudah live:** field `latitude`/`longitude` per level — terisi hampir 100% (province & regency 100%, district 7277/7285, village 83288/83762). Sebagian kecil district/village bisa `null` — tangani sebagai fallback opsional di PRODULI, jangan diasumsikan selalu ada. Dibutuhkan untuk fallback GIS (Dokumen 2 §2c) sebelum titik presisi kader tersedia.

## Endpoint 4 — `POST /api/v1/integration/patients/{patient_id}/pembaruan-lapangan` (SUDAH LIVE)

Beda dari Endpoint 1-3 (sudah live, read-only), endpoint ini **satu-satunya pengecualian tulis**, ability token terpisah `integration:write-field-data` (jangan reuse token baca). Kontrak lengkap + tabel `patient_field_updates` + halaman persetujuan (Vue, sudah live juga) ada di Dokumen 1 §9 dan Dokumen 2 §2c — ringkasan:

```json
// Request
{
  "latitude": -7.0123,
  "longitude": 113.8456,
  "alamat": "...", "kel_desa": "...", "kecamatan": "...", "rt_rw": "...",
  "phone": "...", "pekerjaan": "...", "status_perkawinan": "...",
  "sumber": "kopipu_kunjungan",
  "kopipu_visit_id": 789,
  "kopipu_kader_nama": "Bu Siti"
}

// Response — SELALU pending_review, tidak ada auto-apply
{
  "status": "success",
  "message": "Usulan pembaruan tersimpan, menunggu verifikasi staf Labkesda",
  "data": { "update_id": 123, "status": "pending_review" }
}
```

**Tidak ada endpoint untuk cek status approval** — begitu staf approve, sync rutin PRODULI (`updated_at` delta) otomatis menangkap perubahan. Kalau ditolak, tidak ada perubahan; cukup untuk v1 (YAGNI).

## Endpoint 5 — `GET /api/v1/integration/reference-ranges` (SUDAH LIVE)

Ability sama dengan Endpoint 1-3 (`integration:read-lab-results`). **Tidak dipaginasi** — dataset kecil (~143 baris), satu kali fetch penuh mengembalikan SEMUA data sekaligus (bukan delta sync seperti `patients`/`lab-results`). Reuse controller method yang sama dipakai admin SPA SiLAKES (`PemeriksaanController::getReferenceRanges`).

```json
{
  "status": "success",
  "message": "...",
  "data": {
    "aliases": { "hdl cholesterol": "hdl", "cholesterol": "cholesterol_total", "...": "..." },
    "tensi_alias": "tekanan darah",
    "ranges": {
      "gula_darah_puasa": [
        {
          "id": 1, "parameter_key": "gula_darah_puasa", "gender": null,
          "age_min_years": null, "age_max_years": null,
          "age_min_days": null, "age_max_days": null,
          "value_min": null, "value_max": 130,
          "min_inclusive": false, "max_inclusive": false,
          "category": "normal", "category_label": "Normal",
          "severity_rank": 0, "sort_order": 10
        },
        { "...": "baris berikutnya, dst per kategori" }
      ],
      "hdl": ["..."],
      "creatinine": ["..."]
    }
  }
}
```

**Penting:**

- `ranges` dikelompokkan per `parameter_key` (bukan array datar) — 12 parameter_key SiLAKES total (13 kalau menghitung `tensi_sistolik`/`tensi_diastolik` sebagai dua baris terpisah untuk parameter komposit Tekanan Darah).
- `value_min`/`value_max` nullable di kedua sisi (band terbuka ke satu arah, mis. `value_max: null` berarti tidak ada batas atas). `min_inclusive`/`max_inclusive` menentukan apakah batas itu `>=`/`<=` (true) atau `>`/`<` (false, strict) — WAJIB dihormati persis, salah satu sumber bug presisi yang pernah ditemukan & diperbaiki di SiLAKES sebelum endpoint ini dibuat.
- `severity_rank` (0 = paling aman, naik sesuai keparahan) — dipakai PRODULI sebagai sumber "exceeded" (`severity_rank > 0`) untuk 5 dari 6 parameter `RiskClassificationService` (lihat `App\Services\Risk\SilakesReferenceRangeService`). **Creatinine sengaja dikecualikan** — lihat Dokumen 1 §3.1.
- Data ini STATIS (standar medis, bukan operasional) — cukup sync ulang penuh (truncate+reinsert) tiap kali `SyncSilakesService::run()` jalan, tidak perlu delta/cursor seperti Endpoint 1-2.

## Yang harus dibangun di PRODULI (checklist untuk prompt SyncSilakesService)

1. HTTP client service + helper generate `X-Signature`/`X-Timestamp`.
2. Sync job terjadwal, pull pakai cursor sampai `has_more=false`, simpan `next_cursor` & timestamp sync terakhir per endpoint sebagai `since` di sync berikutnya (delta sync).
3. Local read-cache (`patients_cache`, `lab_results_cache`) agar PRODULI tetap jalan saat SiLAKES down.
4. Idempotency saat simpan hasil sync (key: `patient_id`/`lab_result_id`, bukan insert baru terus).
5. Proses rekonsiliasi `kel_desa`/`kecamatan` → `wilayah_mapping` → `desa_id`/`puskesmas_id`.
6. Endpoint 1-3 dan 5 **read-only mutlak** (GET saja). Endpoint 4 (di atas) adalah **satu-satunya** pengecualian tulis, dipanggil dari `VisitReportService` — jangan generalisasi jadi endpoint tulis lain.
