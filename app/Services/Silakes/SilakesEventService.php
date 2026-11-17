<?php

namespace App\Services\Silakes;

use App\Models\PatientsCache;
use App\Models\PengirimanSampelPasien;
use App\Models\SilakesEventReceipt;
use App\Models\SilakesFieldUpdateRef;
use App\Services\Notification\NotifiableTarget;
use App\Services\Notification\NotificationPayload;
use App\Services\Notification\NotifyService;
use App\Services\Realtime\RealtimeBroadcastService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Menerapkan keputusan staf Labkesda atas usulan dari PRODULI SEKETIKA (revisi 2026-11-17,
 * docs/planning/16 §12): pendaftaran pasien baru dan usulan perubahan data. Tiga jalur masuk
 * memakai service ini supaya hasilnya selalu sama:
 *  - event yang didorong SiLAKES (SilakesEventController),
 *  - balasan langsung saat mendaftar (RegisterPasienBaruToSilakesJob, NIK ternyata sudah ada),
 *  - pengecekan berkala (produli:cek-pendaftaran-silakes) bila event tidak sampai.
 *
 * Setiap penerapan aman diulang: baris yang sudah di status tujuan tidak diubah dan pengaju
 * tidak dinotifikasi dua kali.
 */
class SilakesEventService
{
    public function __construct(
        private readonly SyncSilakesService $sync,
        private readonly NotifyService $notify,
        private readonly RealtimeBroadcastService $realtime,
    ) {}

    /**
     * @param  array{event_id: string, event: string, data: array<string, mixed>}  $event
     * @return bool false bila event_id sudah pernah diproses
     */
    public function terima(array $event): bool
    {
        try {
            SilakesEventReceipt::create(['event_id' => $event['event_id'], 'event' => $event['event'], 'received_at' => now()]);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), 'UNIQUE')) {
                return false;
            }
            throw $e;
        }

        $data = $event['data'];
        match ($event['event']) {
            'patient_registration.approved', 'patient_registration.rejected' => $this->terapkanPendaftaran($data),
            'patient_field_update.approved', 'patient_field_update.rejected' => $this->terapkanPerubahan($data, $event['event'] === 'patient_field_update.approved'),
            default => Log::info('Event SiLAKES tidak dikenal, diabaikan', ['event' => $event['event']]),
        };

        return true;
    }

    // ------------------------------------------------------------------ pendaftaran pasien baru

    /**
     * @param  array{registration_proposal_id?: ?int, status: string, produli_pengiriman_sampel_pasien_id?: ?int,
     *               catatan_reviewer?: ?string, patient?: ?array<string, mixed>}  $data
     */
    public function terapkanPendaftaran(array $data, ?PengirimanSampelPasien $baris = null): void
    {
        $baris ??= $this->cariBaris($data);
        if (! $baris) {
            // Baris sudah dihapus dari antrian, tapi pasiennya tetap sah terdaftar di Labkesda.
            if (($data['status'] ?? null) === 'approved' && is_array($data['patient'] ?? null)) {
                $this->sync->upsertPatient($data['patient']);
            }

            return;
        }

        $status = $data['status'] ?? null;
        $patientRow = $data['patient'] ?? null;

        if (in_array($status, ['approved', 'sudah_terdaftar'], true) && is_array($patientRow)) {
            if ($baris->registrasi_status === 'terdaftar' && $baris->external_patient_id !== null) {
                return;
            }

            $pasien = DB::transaction(function () use ($baris, $patientRow, $data, $status) {
                $pasien = $this->sync->upsertPatient($patientRow);

                // Desa/kecamatan tidak terpetakan: tanpa ini pasien yang baru didaftarkan hilang
                // dari daftar puskesmas pengirimnya sendiri. 'manual' = tidak ditimpa sinkron.
                $puskesmasPengirim = $baris->pengirimanSampel?->puskesmas_id;
                if ($pasien->puskesmas_id === null && $puskesmasPengirim !== null) {
                    $pasien->update(['puskesmas_id' => $puskesmasPengirim, 'puskesmas_resolution_method' => 'manual']);
                }
                $baris->update([
                    'external_patient_id' => $pasien->external_patient_id,
                    'nama_snapshot' => $pasien->nama,
                    'registration_proposal_ref' => $data['registration_proposal_id'] ?? $baris->registration_proposal_ref,
                    'registrasi_status' => 'terdaftar',
                    'registrasi_no_reg' => $pasien->no_reg,
                    'registrasi_catatan' => $status === 'sudah_terdaftar' ? 'NIK ini ternyata sudah terdaftar di Labkesda; baris ditautkan ke pasien tersebut.' : null,
                    'registrasi_diperbarui_at' => now(),
                ]);

                return $pasien;
            });

            $this->kabariPengisi($baris, 'pasien_baru_terdaftar', 'Pasien baru terdaftar di Labkesda',
                $status === 'sudah_terdaftar'
                    ? "{$pasien->nama} ternyata sudah terdaftar di Labkesda (no. reg {$pasien->no_reg}). Antrian memakai data pasien tersebut."
                    : "{$pasien->nama} sudah diverifikasi Labkesda, no. reg {$pasien->no_reg}.",
                ['patient_id' => $pasien->id]);

            return;
        }

        if ($status === 'rejected') {
            if ($baris->registrasi_status === 'ditolak') {
                return;
            }
            $catatan = trim((string) ($data['catatan_reviewer'] ?? ''));
            $baris->update([
                'registrasi_status' => 'ditolak',
                'registrasi_catatan' => $catatan !== '' ? $catatan : null,
                'registrasi_diperbarui_at' => now(),
            ]);

            $this->kabariPengisi($baris, 'pasien_baru_ditolak', 'Pendaftaran pasien baru ditolak Labkesda',
                "Pendaftaran {$baris->nama_snapshot} ditolak".($catatan !== '' ? ": {$catatan}" : '.').' Periksa datanya, hapus dari antrian, lalu daftarkan ulang bila perlu.');
        }
    }

    private function cariBaris(array $data): ?PengirimanSampelPasien
    {
        if (! empty($data['produli_pengiriman_sampel_pasien_id'])) {
            $baris = PengirimanSampelPasien::find($data['produli_pengiriman_sampel_pasien_id']);
            if ($baris) {
                return $baris;
            }
        }

        return ! empty($data['registration_proposal_id'])
            ? PengirimanSampelPasien::where('registration_proposal_ref', $data['registration_proposal_id'])->latest('id')->first()
            : null;
    }

    // ------------------------------------------------------------------ usulan perubahan data

    /**
     * @param  array{update_id: int, catatan_reviewer?: ?string, patient?: ?array<string, mixed>}  $data
     */
    public function terapkanPerubahan(array $data, bool $disetujui): void
    {
        // Data pasien terbaru diterapkan walau usulan ini tidak tercatat di sini (mis. diajukan
        // sebelum fitur ini ada): perubahan yang sudah disetujui tidak perlu menunggu sinkron 02:00.
        if ($disetujui && is_array($data['patient'] ?? null)) {
            $this->sync->upsertPatient($data['patient']);
        }

        $ref = SilakesFieldUpdateRef::where('silakes_update_id', $data['update_id'] ?? 0)->first();
        if (! $ref || $ref->status !== 'pending_review') {
            return;
        }

        $ref->update([
            'status' => $disetujui ? 'approved' : 'rejected',
            'catatan_reviewer' => $data['catatan_reviewer'] ?? null,
            'diputuskan_at' => now(),
        ]);

        $this->broadcast($ref->patient?->puskesmas_id, $ref->user_id, 'pasien.berubah', ['patient_id' => $ref->patients_cache_id]);

        // Satu notifikasi per pengajuan, setelah SEMUA field-nya diputuskan.
        $satuPengajuan = SilakesFieldUpdateRef::where('usulan_id', $ref->usulan_id)->get();
        if ($satuPengajuan->contains('status', 'pending_review') || ! $ref->user) {
            return;
        }

        $setuju = $satuPengajuan->where('status', 'approved')->count();
        $tolak = $satuPengajuan->where('status', 'rejected')->count();
        $nama = $ref->patient?->nama ?? 'pasien';
        $alasan = $satuPengajuan->where('status', 'rejected')->pluck('catatan_reviewer')->filter()->unique()->implode('; ');

        $body = match (true) {
            $tolak === 0 => "Usulan perubahan data {$nama} disetujui Labkesda dan sudah berlaku.",
            $setuju === 0 => "Usulan perubahan data {$nama} ditolak Labkesda".($alasan !== '' ? ": {$alasan}" : '.'),
            default => "Usulan perubahan data {$nama}: {$setuju} disetujui, {$tolak} ditolak".($alasan !== '' ? " ({$alasan})" : '').'.',
        };

        $this->kirim($ref->user, new NotificationPayload(
            type: 'usulan_pasien_diputuskan',
            title: $tolak === 0 ? 'Usulan data pasien disetujui' : 'Usulan data pasien diputuskan',
            body: $body,
            data: ['type' => 'usulan_pasien_diputuskan', 'title' => $tolak === 0 ? 'Usulan data pasien disetujui' : 'Usulan data pasien diputuskan',
                'body' => $body, 'patient_id' => $ref->patients_cache_id, 'patient_nama' => $nama, 'disetujui' => $setuju, 'ditolak' => $tolak],
        ));
    }

    // ------------------------------------------------------------------ notifikasi

    private function kabariPengisi(PengirimanSampelPasien $baris, string $type, string $title, string $body, array $data = []): void
    {
        $baris->loadMissing('pengirimanSampel', 'diinputOleh');
        $payload = ['pengiriman_sampel_id' => $baris->pengiriman_sampel_id, 'baris_id' => $baris->id];

        $this->broadcast($baris->pengirimanSampel?->puskesmas_id, $baris->diinput_oleh_user_id, 'pengiriman_sampel.berubah', $payload);

        if ($baris->diinputOleh) {
            // title/body ikut disimpan di data: bel notifikasi PRODULI hanya membaca kolom data.
            $this->kirim($baris->diinputOleh, new NotificationPayload(type: $type, title: $title, body: $body,
                data: ['type' => $type, 'title' => $title, 'body' => $body, 'patient_nama' => $baris->nama_snapshot, ...$payload, ...$data]));
        }
    }

    private function kirim($user, NotificationPayload $payload): void
    {
        try {
            $this->notify->notify(NotifiableTarget::user($user), $payload, ['push', 'fcm', 'ws']);
        } catch (Throwable $e) {
            // Notifikasi gagal tidak boleh membatalkan data yang sudah diterapkan.
            report($e);
        }
    }

    /** Sinyal "muat ulang" ke layar yang sedang terbuka; gagal kirim tidak menggagalkan apa pun. */
    private function broadcast(?int $puskesmasId, ?int $userId, string $event, array $payload): void
    {
        $topics = array_filter([
            $puskesmasId ? "puskesmas:{$puskesmasId}" : null,
            'role:super_admin',
            $userId ? "user:{$userId}" : null,
        ]);
        foreach ($topics as $topic) {
            try {
                $this->realtime->broadcast($topic, $event, $payload);
            } catch (Throwable $e) {
                Log::info('Sinyal realtime tidak terkirim', ['topic' => $topic, 'event' => $event, 'error' => $e->getMessage()]);
            }
        }
    }
}
