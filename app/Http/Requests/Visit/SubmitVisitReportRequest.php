<?php

namespace App\Http\Requests\Visit;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class SubmitVisitReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Gerbang sebenarnya dicek lewat Policy (VisitAssignmentPolicy::submitReport) di
        // controller -- request ini cuma validasi bentuk data. Anti-fraud SUBSTANTIF (GPS
        // basi, geofence, live-camera, dst) ada di VisitValidationService, bukan di sini.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'assignment_id' => ['required', 'integer', 'exists:visit_assignments,id'],
            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'gps_accuracy_meters' => ['nullable', 'numeric', 'min:0'],
            // Wajib (bukan default now()) -- timestamp GPS device adalah data ANTI-FRAUD
            // (GpsActiveCheck), kalau boleh kosong lalu di-default now() di server, kliennya
            // yang malah dipercaya begitu saja tanpa bukti waktu pengambilan yang sebenarnya.
            'gps_captured_at' => ['required', 'date'],
            'captured_live' => ['required', 'boolean'],
            'is_offline' => ['nullable', 'boolean'],
            'client_submission_id' => ['nullable', 'string', 'max:100'],
            'face_detected_client_side' => ['nullable', 'boolean'],
            'kondisi' => ['required', 'string', 'max:100'],
            'catatan' => ['nullable', 'string'],
            'confirmed_patient_location' => ['nullable', 'boolean'],

            // Usulan pelengkapan/koreksi data pasien (docs/planning/01 §9, desain UI
            // /app/kunjungan/[id]) -- SEMUA opsional, kader isi apa yang sempat digali saat
            // kunjungan. Selalu masuk sebagai pending_review di SiLAKES, tidak pernah auto-apply.
            'alamat' => ['nullable', 'string', 'max:500'],
            'kel_desa' => ['nullable', 'string', 'max:100'],
            'kecamatan' => ['nullable', 'string', 'max:100'],
            'rt_rw' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:20'],
            'pekerjaan' => ['nullable', 'string', 'max:100'],
            'status_perkawinan' => ['nullable', 'string', 'max:50'],
            'golongan_darah' => ['nullable', 'string', 'max:5'],
            'agama' => ['nullable', 'string', 'max:50'],
            'is_bpjs' => ['nullable', 'boolean'],
            'no_bpjs' => ['nullable', 'string', 'max:50'],
            'jenis_prolanis' => ['nullable', 'string', 'max:50'],
            'jenis_perokok' => ['nullable', 'string', 'max:50'],

            // Pemeriksaan saat kunjungan (docs/planning/02 §3 tabel visit_reports) -- SEMUA
            // opsional, BUKAN bagian dari 7-layer VisitValidationService (itu anti-fraud
            // kunjungan, bukan data medis), dan bukan pemeriksaan lab formal (tidak masuk
            // lab_results_cache/RiskClassificationService).
            'gda' => ['nullable', 'numeric', 'min:0'],
            'gdp' => ['nullable', 'numeric', 'min:0'],
            'gd2jpp' => ['nullable', 'numeric', 'min:0'],
            'uric_acid' => ['nullable', 'numeric', 'min:0'],
            'cholesterol' => ['nullable', 'numeric', 'min:0'],
            'systolic' => ['nullable', 'integer', 'min:0'],
            'diastolic' => ['nullable', 'integer', 'min:0'],
            'keluhan' => ['nullable', 'string'],
            // REVISI (kembali eksklusif, permintaan user "harus pilih salah satu") -- wire
            // format tetap array (max 1 elemen) supaya kompatibel dgn draft lama yg sempat
            // dibuat semasa Fase 2 (multi-tindakan) tanpa perlu migrasi data historis.
            'tindakan' => ['nullable', 'array', 'max:1'],
            'tindakan.*' => ['string', 'in:diberi_obat,dirujuk_puskesmas,tidak_ada'],
            // Wajib diisi HANYA kalau 'dirujuk_puskesmas' ada di antara tindakan yang dipilih.
            'cara_rujukan' => [
                Rule::requiredIf(fn () => in_array('dirujuk_puskesmas', (array) $this->input('tindakan', []), true)),
                'nullable', 'string', 'in:datang_sendiri,dijemput_ambulan,diantar_keluarga,diantar_nakes_kader',
            ],
            // Detail obat (permintaan user) -- relevan HANYA kalau tindakan=['diberi_obat'],
            // bisa >1 obat. Diisi siapa pun yang submit laporan ini (kader ATAU nakes) -- sama
            // seperti tindakan sendiri, tidak digerbang role. dosis/frekuensi opsional (kader
            // kadang cuma sempat catat nama obatnya saja).
            'obat_detail' => ['nullable', 'array'],
            'obat_detail.*.nama' => ['required', 'string', 'max:255'],
            'obat_detail.*.dosis' => ['nullable', 'string', 'max:100'],
            'obat_detail.*.frekuensi' => ['nullable', 'string', 'max:100'],

            // PMO mingguan kader (revisi Bu Kadis) -- opsional, sama seperti field pemeriksaan
            // klinis di atas.
            'kepatuhan_obat' => ['nullable', 'string', 'in:patuh,kurang_patuh,tidak_patuh'],
            'sisa_obat' => ['nullable', 'string', 'in:cukup,menipis,habis'],

            // Kunjungan berombongan (docs/planning/02 §16) -- kehadiran AKTUAL. Opsional: kalau
            // tidak dikirim sama sekali, VisitReportService pre-fill dari
            // visit_assignment_companions milik assignment ini (lihat attendeeKaderIds() di
            // bawah). Kalau dikirim (termasuk array kosong []), pakai PERSIS itu -- kader primer
            // sedang mengoreksi (ada yang batal ikut / ada tambahan tak terencana).
            'attendee_kader_ids' => ['nullable', 'array'],
            'attendee_kader_ids.*' => ['integer', 'exists:kader,id'],
        ];
    }

    /**
     * Field usulan data pasien (docs/planning/01 §9) yang benar-benar diisi klien -- dipakai
     * VisitReportController untuk membangun $patientFieldUpdates tanpa ikut menyeret field
     * laporan kunjungan lain (kondisi, catatan, dst) yang sudah punya jalur sendiri.
     *
     * @return array<string, mixed>
     */
    public function patientFieldUpdates(): array
    {
        $updates = Arr::only($this->validated(), [
            'alamat', 'kel_desa', 'kecamatan', 'rt_rw', 'phone', 'pekerjaan',
            'status_perkawinan', 'golongan_darah', 'agama', 'is_bpjs', 'no_bpjs',
            'jenis_prolanis', 'jenis_perokok',
        ]);

        // "boolean" rule tidak meng-cast nilai di validated() (tetap '1'/'0' string dari
        // multipart) -- cast eksplisit di sini supaya payload yang di-relay ke SiLAKES pasti
        // berupa JSON boolean asli, bukan string.
        if (array_key_exists('is_bpjs', $updates)) {
            $updates['is_bpjs'] = $this->boolean('is_bpjs');
        }

        return $updates;
    }

    /**
     * Pemeriksaan saat kunjungan (docs/planning/02 §3) yang benar-benar diisi klien -- dipakai
     * VisitReportController untuk diteruskan apa adanya sebagai kolom visit_reports, terpisah
     * dari $patientFieldUpdates() (itu usulan data pasien ke SiLAKES, ini murni catatan lokal).
     *
     * @return array<string, mixed>
     */
    public function pemeriksaan(): array
    {
        return Arr::only($this->validated(), [
            'gda', 'gdp', 'gd2jpp', 'uric_acid', 'cholesterol',
            'systolic', 'diastolic', 'keluhan', 'tindakan', 'obat_detail', 'cara_rujukan',
            'kepatuhan_obat', 'sisa_obat',
        ]);
    }

    /**
     * true kalau klien MENGIRIM field attendee_kader_ids sama sekali (termasuk array kosong)
     * -- dipakai VisitReportService untuk membedakan "tidak dikirim, pre-fill dari
     * visit_assignment_companions" vs "dikirim eksplisit, pakai apa adanya (koreksi kader)".
     */
    public function hasAttendeeOverride(): bool
    {
        return $this->has('attendee_kader_ids');
    }

    /**
     * @return array<int, int>
     */
    public function attendeeKaderIds(): array
    {
        return $this->validated('attendee_kader_ids', []);
    }
}
