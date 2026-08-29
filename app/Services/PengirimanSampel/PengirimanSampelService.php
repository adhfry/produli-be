<?php

namespace App\Services\PengirimanSampel;

use App\Jobs\SendProlanisDeliveryToSilakesJob;
use App\Models\PatientsCache;
use App\Models\PengantarSampel;
use App\Models\PengirimanSampel;
use App\Models\PengirimanSampelPasien;
use App\Models\User;
use App\Services\Notification\NotifiableTarget;
use App\Services\Notification\NotificationPayload;
use App\Services\Notification\NotifyService;
use App\Services\Realtime\RealtimeBroadcastService;
use App\Support\DataScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Fase B+C modul "Kirim Data Prolanis ke Labkesda Sumenep" -- penyusunan antrian (Fase B, murni
 * dalam PRODULI) + penugasan kurir/OTW/konfirmasi tiba dengan foto (Fase C). Belum ada
 * pengiriman sungguhan ke SiLAKES sampai batch 'tiba_labkesda' (itu Fase D). Status-machine
 * (satu method per transisi, validasi status berjalan + `lockForUpdate()` saat rawan race) mirror
 * pola `RujukanService::konfirmasi()`/`inputTindakanLanjutan()` dan
 * `VisitReportService::submit()`, termasuk notifikasi try/catch-wrapped yang tidak pernah
 * menggagalkan transisi state itu sendiri.
 */
class PengirimanSampelService
{
    public function __construct(
        private readonly NotifyService $notifyService,
        private readonly RealtimeBroadcastService $realtimeBroadcast,
    ) {}

    public function create(User $actor, ?int $puskesmasIdOverride = null): PengirimanSampel
    {
        $puskesmasId = $this->resolvePuskesmasId($actor, $puskesmasIdOverride);

        return PengirimanSampel::create([
            'puskesmas_id' => $puskesmasId,
            'status' => 'draft',
            'dibuat_oleh' => $actor->id,
        ]);
    }

    /**
     * @param  array{external_patient_id?: int, name?: string, nik?: string, gender?: string,
     *     tempat_lahir?: string, tgl_lahir?: string, phone?: string, alamat?: string,
     *     rt_rw?: ?string, kel_desa?: ?string, kecamatan?: ?string, no_bpjs?: ?string,
     *     jenis_prolanis?: ?string}  $data
     */
    public function addPatient(PengirimanSampel $batch, array $data): PengirimanSampelPasien
    {
        $this->assertDraft($batch);

        return DB::transaction(function () use ($batch, $data) {
            $nextUrutan = (int) $batch->pasien()->max('urutan') + 1;

            if (! empty($data['external_patient_id'])) {
                $patient = PatientsCache::where('external_patient_id', $data['external_patient_id'])->first();

                if (! $patient) {
                    throw ValidationException::withMessages([
                        'external_patient_id' => ['Pasien tidak ditemukan.'],
                    ]);
                }

                if ($batch->pasien()->where('external_patient_id', $patient->external_patient_id)->exists()) {
                    throw ValidationException::withMessages([
                        'external_patient_id' => ['Pasien ini sudah ada di antrian.'],
                    ]);
                }

                return PengirimanSampelPasien::create([
                    'pengiriman_sampel_id' => $batch->id,
                    'external_patient_id' => $patient->external_patient_id,
                    'nama_snapshot' => $patient->nama,
                    'jenis_prolanis_snapshot' => $patient->jenis_prolanis,
                    'urutan' => $nextUrutan,
                ]);
            }

            // Pasien baru -- belum ada di SiLAKES sama sekali, identitas lengkap wajib
            // diisi sekarang (lihat docblock migrasi create_pengiriman_sampel_pasien_table).
            return PengirimanSampelPasien::create([
                'pengiriman_sampel_id' => $batch->id,
                'nama_snapshot' => $data['name'],
                'jenis_prolanis_snapshot' => $data['jenis_prolanis'] ?? null,
                'urutan' => $nextUrutan,
                'data_pasien_baru_nik' => $data['nik'],
                'data_pasien_baru_gender' => $data['gender'],
                'data_pasien_baru_tempat_lahir' => $data['tempat_lahir'],
                'data_pasien_baru_tgl_lahir' => $data['tgl_lahir'],
                'data_pasien_baru_phone' => $data['phone'],
                'data_pasien_baru_alamat' => $data['alamat'],
                'data_pasien_baru_rt_rw' => $data['rt_rw'] ?? null,
                'data_pasien_baru_kel_desa' => $data['kel_desa'] ?? null,
                'data_pasien_baru_kecamatan' => $data['kecamatan'] ?? null,
                'data_pasien_baru_no_bpjs' => $data['no_bpjs'] ?? null,
            ]);
        });
    }

    public function removePatient(PengirimanSampel $batch, PengirimanSampelPasien $pasien): void
    {
        $this->assertDraft($batch);

        if ($pasien->pengiriman_sampel_id !== $batch->id) {
            throw ValidationException::withMessages([
                'pasien' => ['Baris pasien ini bukan bagian dari antrian ini.'],
            ]);
        }

        $pasien->delete();
    }

    /**
     * Urutan baru "meja A-B-C" -- $orderedPasienIds adalah SELURUH id baris pasien batch ini,
     * dalam urutan yang diinginkan (hasil drag-reorder ATAU klik "ambil urutan" di frontend,
     * keduanya bermuara ke array urutan penuh yang sama). Ditulis lewat 2 tahap (offset negatif
     * dulu, baru nilai final) supaya tidak pernah bentrok dengan unique constraint
     * (pengiriman_sampel_id, urutan) di tengah proses, dan dibungkus `lockForUpdate()` pada baris
     * batch supaya 2 admin yang menyusun ulang hampir bersamaan tidak saling menimpa.
     *
     * @param  int[]  $orderedPasienIds
     */
    public function reorder(PengirimanSampel $batch, array $orderedPasienIds): PengirimanSampel
    {
        $this->assertDraft($batch);

        DB::transaction(function () use ($batch, $orderedPasienIds) {
            PengirimanSampel::whereKey($batch->id)->lockForUpdate()->first();

            $existingIds = $batch->pasien()->pluck('id')->all();
            sort($existingIds);
            $sortedInput = $orderedPasienIds;
            sort($sortedInput);

            if ($existingIds !== $sortedInput) {
                throw ValidationException::withMessages([
                    'urutan' => ['Daftar urutan tidak cocok dengan pasien yang ada di antrian ini.'],
                ]);
            }

            foreach ($orderedPasienIds as $index => $pasienId) {
                PengirimanSampelPasien::whereKey($pasienId)->update(['urutan' => -($index + 1)]);
            }
            foreach ($orderedPasienIds as $index => $pasienId) {
                PengirimanSampelPasien::whereKey($pasienId)->update(['urutan' => $index + 1]);
            }
        });

        return $batch->fresh('pasien');
    }

    public function lock(PengirimanSampel $batch, User $actor): PengirimanSampel
    {
        $this->assertDraft($batch);

        if ($batch->pasien()->count() === 0) {
            throw ValidationException::withMessages([
                'pasien' => ['Antrian belum punya pasien, tidak bisa dikunci.'],
            ]);
        }

        $batch->update([
            'status' => 'terkunci',
            'dikunci_at' => now(),
            'dikunci_oleh' => $actor->id,
        ]);

        return $batch->fresh();
    }

    /**
     * "Edit Daftar" -- balik ke draft supaya isi/urutan antrian bisa diubah lagi. Hanya valid
     * dari 'terkunci' (begitu sudah 'ditugaskan', Fase C, kurir sudah terikat ke batch ini --
     * harus dibatalkan penugasannya dulu, bukan langsung unlock).
     */
    public function unlock(PengirimanSampel $batch): PengirimanSampel
    {
        if ($batch->status !== 'terkunci') {
            throw ValidationException::withMessages([
                'status' => ['Hanya antrian berstatus terkunci yang bisa diedit ulang.'],
            ]);
        }

        $batch->update([
            'status' => 'draft',
            'dikunci_at' => null,
            'dikunci_oleh' => null,
        ]);

        return $batch->fresh();
    }

    public function cancel(PengirimanSampel $batch): PengirimanSampel
    {
        if (! in_array($batch->status, ['draft', 'terkunci', 'ditugaskan'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Antrian yang sudah OTW/tiba tidak bisa dibatalkan lewat sini.'],
            ]);
        }

        $batch->update(['status' => 'dibatalkan']);

        return $batch->fresh();
    }

    /**
     * Tugaskan pengantar sampel -- hanya valid dari 'terkunci' (isi antrian sudah final).
     * `lockForUpdate()` mencegah 2 admin puskesmas menugaskan kurir berbeda ke batch yang sama
     * hampir bersamaan.
     */
    public function assignCourier(PengirimanSampel $batch, int $pengantarSampelId, User $actor): PengirimanSampel
    {
        $updated = DB::transaction(function () use ($batch, $pengantarSampelId, $actor) {
            $locked = PengirimanSampel::whereKey($batch->id)->lockForUpdate()->first();

            if ($locked->status !== 'terkunci') {
                throw ValidationException::withMessages([
                    'status' => ['Kunci daftar antrian dulu sebelum menugaskan pengantar.'],
                ]);
            }

            $pengantar = PengantarSampel::where('id', $pengantarSampelId)
                ->where('puskesmas_id', $batch->puskesmas_id)
                ->where('status_aktif', true)
                ->first();

            if (! $pengantar) {
                throw ValidationException::withMessages([
                    'pengantar_sampel_id' => ['Pengantar sampel tidak ditemukan, bukan dari puskesmas ini, atau sedang nonaktif.'],
                ]);
            }

            $locked->update([
                'status' => 'ditugaskan',
                'pengantar_sampel_id' => $pengantar->id,
                'ditugaskan_at' => now(),
                'ditugaskan_oleh' => $actor->id,
            ]);

            return $locked->fresh(['puskesmas', 'pengantarSampel.user']);
        });

        $this->notifyCourierAssigned($updated);
        $this->broadcastSignal($updated, 'sampel.ditugaskan');

        return $updated;
    }

    /**
     * Kurir mulai berangkat -- hanya kurir yang ditugaskan ke batch ini sendiri yang boleh
     * memulai (dicek pemanggil lewat Policy, bukan di sini). Mulai dari titik ini sampai
     * confirmArrival(), heartbeat GPS aktif (lihat PengirimanSampelLokasiService).
     */
    public function startOtw(PengirimanSampel $batch): PengirimanSampel
    {
        if ($batch->status !== 'ditugaskan') {
            throw ValidationException::withMessages([
                'status' => ['Antrian ini belum ditugaskan ke Anda, atau sudah OTW/tiba.'],
            ]);
        }

        $batch->update(['status' => 'otw', 'otw_at' => now()]);
        $updated = $batch->fresh(['puskesmas', 'pengantarSampel.user']);

        $this->notifyPuskesmas($updated, 'sampel_otw', 'Pengantar Sampel Berangkat', "{$this->courierName($updated)} sudah berangkat membawa sampel menuju Labkesda Sumenep.", ['push', 'fcm']);
        $this->broadcastSignal($updated, 'sampel.otw');

        return $updated;
    }

    /**
     * Konfirmasi tiba di Labkesda + foto bukti serah-terima (watermark, dibuat client-side
     * mirror pola `/app/kunjungan` -- server TIDAK re-verifikasi watermark, sama filosofi
     * `WatermarkGenerator::isEnabled()===false` di VisitValidationService). Foto diupload
     * SINKRON di LUAR transaction DB (mirror VisitReportService::submit() -- bukti kedatangan
     * adalah bukti inti, kalau upload gagal, konfirmasi ini HARUS ikut gagal).
     */
    public function confirmArrival(PengirimanSampel $batch, UploadedFile $photo, float $lat, float $lng, ?float $accuracy): PengirimanSampel
    {
        if ($batch->status !== 'otw') {
            throw ValidationException::withMessages([
                'status' => ['Antrian ini belum berstatus OTW, tidak bisa dikonfirmasi tiba.'],
            ]);
        }

        $fotoPath = $this->storePhoto($photo);

        $batch->update([
            'status' => 'tiba_labkesda',
            'tiba_at' => now(),
            'foto_bukti_path' => $fotoPath,
            'tiba_gps_lat' => $lat,
            'tiba_gps_lng' => $lng,
            'tiba_gps_accuracy' => $accuracy,
        ]);
        $batch->lokasi()->delete();

        $updated = $batch->fresh(['puskesmas', 'pengantarSampel.user']);

        $this->notifyPuskesmas($updated, 'sampel_tiba', 'Sampel Tiba di Labkesda', "{$this->courierName($updated)} sudah tiba dan menyerahkan sampel di Labkesda Sumenep.", ['push', 'fcm', 'email']);
        $this->broadcastSignal($updated, 'sampel.tiba');

        // Fase D -- job TERPISAH dengan retry (mirror SyncFieldUpdateToSilakesJob), kegagalan/
        // lambatnya SiLAKES TIDAK PERNAH menggagalkan konfirmasi kurir yang sudah tersimpan lokal.
        SendProlanisDeliveryToSilakesJob::dispatch($updated->id);

        return $updated;
    }

    /**
     * Fase D -- dipanggil PollProlanisDeliveryConfirmationCommand setelah SiLAKES melapor staf
     * Labkesda sudah mengkonfirmasi kedatangan (bukan dipicu user PRODULI sama sekali).
     */
    public function markConfirmedBySilakes(PengirimanSampel $batch, ?string $confirmedByName): PengirimanSampel
    {
        if ($batch->status !== 'tiba_labkesda') {
            return $batch;
        }

        $batch->update([
            'status' => 'dikonfirmasi_labkesda',
            'dikonfirmasi_labkesda_at' => now(),
            'dikonfirmasi_labkesda_oleh' => $confirmedByName,
        ]);

        $updated = $batch->fresh(['puskesmas', 'pengantarSampel.user']);

        $this->notifyPuskesmas($updated, 'sampel_dikonfirmasi_labkesda', 'Sampel Dikonfirmasi Labkesda', 'Sampel sudah dikonfirmasi diterima oleh petugas Labkesda Sumenep, siap diproses.', ['push', 'fcm']);
        $this->broadcastSignal($updated, 'sampel.dikonfirmasi_labkesda');

        return $updated;
    }

    /**
     * @return Builder<PengirimanSampel>
     */
    public function scopedQuery(User $user): Builder
    {
        if (DataScope::isFullAccess($user)) {
            return PengirimanSampel::query();
        }

        if ($user->puskesmas_id === null) {
            return PengirimanSampel::query()->whereRaw('1 = 0');
        }

        return PengirimanSampel::query()->where('puskesmas_id', $user->puskesmas_id);
    }

    /**
     * Kandidat pasien utk dipilih ke antrian -- pasien Prolanis (is_prolanis=true) di puskesmas
     * user, dilengkapi tanggal pemeriksaan lab TERAKHIR (withMax, bukan query N+1 per baris).
     *
     * @return Builder<PatientsCache>
     */
    public function patientCandidatesQuery(User $user): Builder
    {
        $query = PatientsCache::query()->where('is_prolanis', true);

        if (! DataScope::isFullAccess($user)) {
            $query->where('puskesmas_id', $user->puskesmas_id);
        }

        return $query->withMax('labResults', 'tanggal_periksa');
    }

    private function assertDraft(PengirimanSampel $batch): void
    {
        if ($batch->status !== 'draft') {
            throw ValidationException::withMessages([
                'status' => ['Antrian ini sudah terkunci/diproses, tidak bisa diubah isinya. Klik "Edit Daftar" dulu kalau masih berstatus terkunci.'],
            ]);
        }
    }

    private function resolvePuskesmasId(User $actor, ?int $override): int
    {
        if (DataScope::isFullAccess($actor)) {
            if (empty($override)) {
                throw ValidationException::withMessages([
                    'puskesmas_id' => ['Wajib diisi untuk super_admin (tidak punya puskesmas sendiri).'],
                ]);
            }

            return $override;
        }

        if ($actor->puskesmas_id === null) {
            throw ValidationException::withMessages([
                'puskesmas_id' => ['Akun Anda belum di-assign ke puskesmas mana pun.'],
            ]);
        }

        return $actor->puskesmas_id;
    }

    private function courierName(PengirimanSampel $batch): string
    {
        return $batch->pengantarSampel?->user?->name ?? 'Pengantar sampel';
    }

    /**
     * Try/catch di sini (bukan di pemanggil) -- mirror VisitReportService::notifyReportSubmitted(),
     * gagal kirim notifikasi TIDAK BOLEH menggagalkan transisi status yang sudah tersimpan.
     */
    private function notifyCourierAssigned(PengirimanSampel $batch): void
    {
        try {
            $courierUser = $batch->pengantarSampel?->user;
            if ($courierUser === null) {
                return;
            }

            $this->notifyService->notify(
                NotifiableTarget::user($courierUser),
                new NotificationPayload(
                    type: 'sampel_ditugaskan',
                    title: 'Penugasan Antar Sampel',
                    body: "Anda ditugaskan mengantar sampel Prolanis {$batch->puskesmas->nama} ke Labkesda Sumenep.",
                    data: [
                        'type' => 'sampel_ditugaskan',
                        'severity' => 'info',
                        'pengiriman_sampel_id' => $batch->id,
                        'action_url' => "/app/pengiriman/{$batch->id}",
                        'action_label' => 'Lihat Tugas',
                    ],
                ),
                ['push', 'fcm'],
            );
        } catch (Throwable $e) {
            Log::warning('PengirimanSampelService::notifyCourierAssigned gagal', [
                'pengiriman_sampel_id' => $batch->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<int, string>  $channels
     */
    private function notifyPuskesmas(PengirimanSampel $batch, string $type, string $title, string $body, array $channels): void
    {
        try {
            $this->notifyService->notify(
                NotifiableTarget::rolesInPuskesmas(['admin_puskesmas', 'pj_prolanis'], $batch->puskesmas_id),
                new NotificationPayload(
                    type: $type,
                    title: $title,
                    body: $body,
                    data: [
                        'type' => $type,
                        'severity' => $type === 'sampel_tiba' ? 'success' : 'info',
                        'pengiriman_sampel_id' => $batch->id,
                        'action_url' => "/dashboard/pengiriman-sampel/{$batch->id}",
                        'action_label' => 'Lihat Antrian',
                    ],
                ),
                $channels,
            );
        } catch (Throwable $e) {
            Log::warning('PengirimanSampelService::notifyPuskesmas gagal', [
                'pengiriman_sampel_id' => $batch->id,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Sinyal dashboard realtime -- mirror VisitReportService::broadcastDashboardSignal(),
     * broadcast ke topic puskesmas DAN role:super_admin sekaligus (super_admin butuh tahu
     * setiap perubahan status pengiriman utk peta live, terlepas dari siapa yang jadi target
     * notifyPuskesmas() di atas). Payload sengaja MINIMAL (cuma id) -- frontend refetch REST,
     * bukan mengandalkan isi payload broadcast (lihat docblock RealtimeBroadcastService).
     */
    private function broadcastSignal(PengirimanSampel $batch, string $event): void
    {
        try {
            $payload = ['pengiriman_sampel_id' => $batch->id];
            $this->realtimeBroadcast->broadcast("puskesmas:{$batch->puskesmas_id}", $event, $payload);
            $this->realtimeBroadcast->broadcast('role:super_admin', $event, $payload);
        } catch (Throwable $e) {
            Log::warning('PengirimanSampelService::broadcastSignal gagal', [
                'pengiriman_sampel_id' => $batch->id,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Mirror persis VisitReportService::storePhoto() -- prefix 'pasien/' taksonomi bucket +
     * partisi tanggal, disk dari config yang sama (foto kunjungan & foto bukti serah-terima
     * sampel sama-sama data sensitif terkait pasien/petugas, tidak perlu disk terpisah).
     */
    private function storePhoto(UploadedFile $photo): string
    {
        $disk = (string) config('produli.storage.visit_photos_disk', 's3');
        $extension = $photo->getClientOriginalExtension() ?: 'jpg';
        $key = 'pasien/pengiriman-sampel/'.now()->format('Y/m/d').'/'.Str::uuid().'.'.$extension;

        $success = Storage::disk($disk)->put($key, file_get_contents($photo->getRealPath()));

        if (! $success) {
            throw new RuntimeException("Gagal upload foto bukti serah-terima ke disk '{$disk}'.");
        }

        return $key;
    }
}
