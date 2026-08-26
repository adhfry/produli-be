<?php

namespace App\Services\Visit;

use App\Mail\VisitAssignedMail;
use App\Models\Kader;
use App\Models\PatientsCache;
use App\Models\RiskClassification;
use App\Models\User;
use App\Models\VisitAssignment;
use App\Models\VisitAssignmentCompanion;
use App\Services\Notification\NotifiableTarget;
use App\Services\Notification\NotificationPayload;
use App\Services\Notification\NotifyService;
use App\Support\DataScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Assign kader ke pasien untuk kunjungan rumah. Lihat docs/planning/02 §2/§2a/§3.
 *
 * Reminder H-1/hari-H (§3/§8) TIDAK di-trigger eksplisit di sini -- NotificationService
 * (produli:send-visit-reminders, jalan twiceDaily) yang polling assignment pending H-1/hari-H
 * secara berkala, jadi assignment yang dibuat lewat assign() otomatis kepantau tanpa perlu
 * dipanggil manual dari sini.
 *
 * Jalur alternatif "phone_contact": pasien risk_level=Berat yang wilayahnya TIDAK resolved
 * (bukan resolved/kecamatan_fallback) tapi punya nomor telepon TETAP boleh ditugaskan --
 * ditemukan lewat investigasi nyata (6 pasien Berat, semuanya unresolvable karena data
 * kecamatan/desa kosong dari SiLAKES) bahwa aturan wilayah-wajib akan membuat pasien risiko
 * tertinggi justru TIDAK PERNAH bisa dikunjungi kalau data wilayahnya kebetulan kosong. Kader
 * diarahkan hubungi dulu lewat telepon (bukan lewat peta), lengkapi alamat saat kunjungan.
 * Pasien Sedang/Ringan TIDAK dapat pengecualian ini -- tetap wajib wilayah resolved seperti biasa.
 */
class VisitAssignmentService
{
    public function __construct(private readonly NotifyService $notifyService) {}

    public function assign(
        PatientsCache $patient,
        Kader $kader,
        User $assignedBy,
        string $scheduledDate,
        string $priority,
    ): VisitAssignment {
        $phoneContactException = $this->isBeratPhoneContactEligible($patient);

        $this->ensureWilayahResolvable($patient, $phoneContactException);
        $this->ensureKaderAvailable($kader, $patient, $phoneContactException);

        return $this->createAssignmentRow($patient, $kader, $assignedBy, $scheduledDate, $priority, $phoneContactException);
    }

    /**
     * Penugasan multi-tanggal (permintaan user) -- admin pilih BEBERAPA tanggal sekaligus utk
     * kader+pasien yang sama (mis. rencana kunjungan PMO sebulan penuh di tanggal-tanggal
     * spesifik, di luar cadence mingguan otomatis yang sudah ada -- lihat CareAssignmentService/
     * CareAssignmentCadenceService). BEDA dari assign() single-date: guard "sudah punya
     * assignment aktif" (ensureKaderAvailable()) SENGAJA DILONGGARKAN di sini -- itu justru
     * tujuan fitur ini (banyak assignment aktif sekaligus utk pasangan yang sama, beda tanggal).
     * Yang tetap dicek: tidak boleh ada 2 assignment aktif di TANGGAL PERSIS yang sama (baik
     * dari batch ini vs assignment lama yang sudah ada, mis. hasil cadence otomatis yang sudah
     * due sebelumnya).
     *
     * @param  string[]  $scheduledDates  Y-m-d, WAJIB sudah unik & terurut (lihat
     *                                    MultiDateVisitAssignmentRequest -- distinct rule)
     * @return VisitAssignment[]
     */
    public function assignMultipleDates(
        PatientsCache $patient,
        Kader $kader,
        User $assignedBy,
        array $scheduledDates,
        string $priority,
    ): array {
        $phoneContactException = $this->isBeratPhoneContactEligible($patient);

        $this->ensureWilayahResolvable($patient, $phoneContactException);
        $this->ensureKaderAvailableForMultipleDates($kader, $patient, $scheduledDates, $phoneContactException);

        return DB::transaction(function () use ($patient, $kader, $assignedBy, $scheduledDates, $priority, $phoneContactException) {
            $assignments = [];
            foreach ($scheduledDates as $scheduledDate) {
                $assignments[] = $this->createAssignmentRow($patient, $kader, $assignedBy, $scheduledDate, $priority, $phoneContactException);
            }

            return $assignments;
        });
    }

    private function createAssignmentRow(
        PatientsCache $patient,
        Kader $kader,
        User $assignedBy,
        string $scheduledDate,
        string $priority,
        bool $phoneContactException,
    ): VisitAssignment {
        return VisitAssignment::create([
            'patient_id' => $patient->id,
            'kader_id' => $kader->id,
            'assigned_by' => $assignedBy->id,
            'scheduled_date' => $scheduledDate,
            'status' => 'pending',
            'priority' => $priority,
            'assignment_method' => $phoneContactException ? 'phone_contact' : 'wilayah_resolved',
            // Jalur normal: dari patients_cache.puskesmas_id (BUKAN literal desa.puskesmas_id
            // seperti draf awal §2a) — supaya kasus kecamatan_fallback (tanpa desa_id sama
            // sekali) tetap ke-snapshot dengan benar. Jalur phone_contact: lokasi pasien TIDAK
            // diketahui (itu justru alasan jalur ini ada) -- puskesmas_id_snapshot (kolom NOT
            // NULL) diisi dari puskesmas KADER, satu-satunya nilai yang pasti valid di kasus ini.
            // Immutable setelah dibuat, tidak pernah di-update lagi.
            'puskesmas_id_snapshot' => $phoneContactException ? $kader->puskesmas_id : $patient->puskesmas_id,
        ]);
    }

    /**
     * Pasien lolos pengecualian phone_contact HANYA kalau risk_level TERBARU-nya Berat DAN
     * nomor telepon terisi (bukan null/string kosong) -- Sedang/Ringan tidak pernah lolos di
     * sini, tetap wajib wilayah resolved lewat ensureWilayahResolvable() seperti biasa.
     */
    private function isBeratPhoneContactEligible(PatientsCache $patient): bool
    {
        if (trim((string) $patient->phone) === '') {
            return false;
        }

        return RiskClassification::where('patient_id', $patient->id)
            ->where('is_latest', true)
            ->where('level', 'berat')
            ->exists();
    }

    /**
     * Bulk assign kader yang SAMA ke banyak pasien sekaligus (docs/planning/02 §12/§16) --
     * PARTIAL SUCCESS by design: tiap pasien divalidasi lewat assign() yang SAMA persis dengan
     * jalur single-assignment (wilayah_status, kader aktif+sepuskesmas, no duplicate aktif),
     * yang gagal dilaporkan alasannya + tetap lanjut ke pasien berikutnya, bukan all-or-nothing.
     * Sengaja TIDAK dibungkus 1 transaksi besar -- tiap assign() sudah atomic per-baris, dan
     * membungkusnya akan membuat satu kegagalan menggagalkan semua yang sudah lolos.
     *
     * $companionKaders (§16, "kunjungan berombongan") BEDA sifat dari $patientIds -- ini
     * precondition BATCH (sama seperti $kader primer sendiri), divalidasi SEKALI di depan lewat
     * ensureCompanionsAvailable(); kalau ada satu saja yang gagal, SELURUH request ditolak
     * (bukan partial-success), bukan per-pasien. Kader yang lolos di-attach ke SETIAP assignment
     * yang berhasil dibuat di batch ini (assignment_id berbeda-beda, kader_id sama semua).
     *
     * @param  array<int, int>  $patientIds
     * @param  array<int, Kader>  $companionKaders
     * @return array{created: array<int, VisitAssignment>, failed: array<int, array{patient_id: int, reason: string}>}
     */
    public function assignBulk(
        array $patientIds,
        Kader $kader,
        array $companionKaders,
        User $assignedBy,
        string $scheduledDate,
        string $priority,
    ): array {
        $this->ensureCompanionsAvailable($kader, $companionKaders);

        $created = [];
        $failed = [];

        foreach ($patientIds as $patientId) {
            $patient = PatientsCache::find($patientId);

            if ($patient === null) {
                $failed[] = ['patient_id' => $patientId, 'reason' => 'Pasien tidak ditemukan.'];

                continue;
            }

            try {
                $assignment = $this->assign($patient, $kader, $assignedBy, $scheduledDate, $priority);
                $this->attachCompanions($assignment, $companionKaders);
                $created[] = $assignment;
            } catch (ValidationException $e) {
                $failed[] = [
                    'patient_id' => $patientId,
                    'reason' => collect($e->errors())->flatten()->first() ?? 'Validasi gagal.',
                ];
            }
        }

        if ($created !== []) {
            $this->notifyAssignedKaders($kader, $companionKaders, count($created), $scheduledDate);
        }

        return ['created' => $created, 'failed' => $failed];
    }

    /**
     * Batalkan penugasan (keputusan Kepala Dinas: admin_puskesmas/pj_prolanis salah tugas atau
     * typo kader/pasien -- lihat VisitAssignmentPolicy::cancel(), TANPA perlu approval
     * super_admin, cukup modal konfirmasi di frontend). Cuma boleh dari status 'pending'/
     * 'in_progress' -- assignment yang sudah 'completed' (laporan sudah masuk) atau
     * 'cancelled' (sudah dibatalkan sebelumnya) ditolak, tidak ada gunanya dibatalkan lagi.
     * Kader/nakes yang ditugaskan SELALU dinotif (push+fcm, sama kanal dengan notifyAssignedKaders())
     * supaya tidak diam-diam terus mengerjakan tugas yang sudah tidak berlaku.
     */
    public function cancel(VisitAssignment $assignment, User $actor, ?string $reason = null): VisitAssignment
    {
        if (! in_array($assignment->status, ['pending', 'in_progress'], true)) {
            throw ValidationException::withMessages([
                'assignment' => ["Assignment ini sudah berstatus {$assignment->status}, tidak bisa dibatalkan."],
            ]);
        }

        $assignment->update(['status' => 'cancelled']);

        $assignee = $assignment->assigneeUser();

        if ($assignee !== null) {
            $this->notifyService->notify(
                NotifiableTarget::user($assignee),
                new NotificationPayload(
                    type: 'visit_assignment_cancelled',
                    title: 'Penugasan Dibatalkan',
                    body: $reason
                        ? "Penugasan kunjungan tanggal {$assignment->scheduled_date->toDateString()} dibatalkan oleh {$actor->name}: {$reason}"
                        : "Penugasan kunjungan tanggal {$assignment->scheduled_date->toDateString()} dibatalkan oleh {$actor->name}.",
                    data: [
                        'type' => 'visit_assignment_cancelled',
                        'visit_assignment_id' => $assignment->id,
                        'reason' => $reason,
                        'action_url' => '/app/tugas',
                        'action_label' => 'Lihat Tugas',
                    ],
                ),
                // 3 kanal (bukan cuma push+fcm) -- kader/nakes yang sedang di lapangan mungkin
                // tidak buka app tepat waktu, jadwalnya berubah mendadak dan perlu diketahui
                // SEGERA (beda dari notifikasi rutin seperti penugasan baru terjadwal).
                ['push', 'fcm', 'email'],
            );
        }

        return $assignment->fresh();
    }

    /**
     * "Companion divalidasi sama seperti kader primer: aktif, sepuskesmas" (docs/planning/02
     * §16) -- "sepuskesmas" di sini berarti sepuskesmas dengan KADER PRIMER (rekan satu tim
     * yang bisa benar-benar ikut kunjungan bareng), bukan cuma sepuskesmas dengan actor yang
     * login. Gagal SATU companion saja -> tolak SELURUH batch (422), tidak ada assignment yang
     * dibuat sama sekali -- konsisten dengan bagaimana kader primer sendiri diperlakukan.
     *
     * @param  array<int, Kader>  $companionKaders
     */
    private function ensureCompanionsAvailable(Kader $primary, array $companionKaders): void
    {
        foreach ($companionKaders as $companion) {
            if (! $companion->status_aktif) {
                throw ValidationException::withMessages([
                    'companion_kader_ids' => ["Kader pendamping (id={$companion->id}) tidak aktif."],
                ]);
            }

            if ($companion->puskesmas_id !== $primary->puskesmas_id) {
                throw ValidationException::withMessages([
                    'companion_kader_ids' => ["Kader pendamping (id={$companion->id}) bukan dari puskesmas yang sama dengan kader primer."],
                ]);
            }
        }
    }

    /**
     * @param  array<int, Kader>  $companionKaders
     */
    private function attachCompanions(VisitAssignment $assignment, array $companionKaders): void
    {
        foreach ($companionKaders as $companion) {
            VisitAssignmentCompanion::create([
                'assignment_id' => $assignment->id,
                'kader_id' => $companion->id,
            ]);
        }
    }

    /**
     * 1 email ringkas per kader (primer + pendamping) yang kena batch ini (docs/planning/02
     * §16) -- BUKAN 1 email per pasien (hindari spam kalau PJ tugaskan banyak pasien
     * sekaligus). Isi SENGAJA minimal, TIDAK sebut nama/data pasien (data kesehatan tidak masuk
     * kanal email yang kurang terjamin dibanding aplikasi). Dipanggil HANYA kalau minimal 1
     * assignment berhasil dibuat di batch ini.
     *
     * VisitAssignedMail = notifikasi NON-KRITIS (docs/planning/02 §17) -- hormati
     * users.email_notifications_enabled, BEDA dari email keamanan akun (aktivasi/reset
     * password) yang SELALU terkirim apa pun preferensinya (bukan tanggung jawab method ini,
     * itu tidak lewat jalur ini sama sekali).
     *
     * @param  array<int, Kader>  $companionKaders
     */
    private function notifyAssignedKaders(Kader $primary, array $companionKaders, int $taskCount, string $scheduledDate): void
    {
        foreach ([$primary, ...$companionKaders] as $kader) {
            if ($kader->user?->email && $kader->user->email_notifications_enabled) {
                Mail::to($kader->user->email)->queue(new VisitAssignedMail($kader->user->name, $taskCount, $scheduledDate));
            }

            // 'push'+'fcm' -- SEBELUMNYA cuma email (gated preferensi user, bisa dimatikan),
            // jadi kader/nakes bisa sama sekali tidak sadar ada tugas baru sampai buka app
            // sendiri. Push/FCM TIDAK digerbang email_notifications_enabled (preferensi itu
            // khusus email), dan tetap dikirim walau kader->user null-check gagal di atas.
            if ($kader->user !== null) {
                $this->notifyService->notify(
                    NotifiableTarget::user($kader->user),
                    new NotificationPayload(
                        type: 'visit_assigned',
                        title: 'Tugas Kunjungan Baru',
                        body: $taskCount > 1
                            ? "{$taskCount} kunjungan baru dijadwalkan {$scheduledDate}."
                            : "Kunjungan baru dijadwalkan {$scheduledDate}.",
                        data: [
                            'type' => 'visit_assigned',
                            'task_count' => $taskCount,
                            'scheduled_date' => $scheduledDate,
                            'action_url' => '/app/tugas',
                            'action_label' => 'Lihat Tugas',
                        ],
                    ),
                    ['push', 'fcm'],
                );
            }
        }
    }

    /**
     * Tolak assignment kalau PRODULI tidak tahu wilayah pasien cukup jelas untuk dikirim kader —
     * wilayah_status=resolved (desa presisi) ATAU puskesmas_resolution_method=kecamatan_fallback
     * (kecamatan cuma 1 puskesmas) keduanya dianggap cukup. Selain itu ditolak (docs/planning/02 §2a,
     * diperluas dari "wilayah_status=resolved" saja).
     *
     * $phoneContactException: pasien Berat + nomor telepon tersedia -- SELURUH pemeriksaan di
     * method ini di-skip. Wilayah memang TIDAK diketahui, itu justru alasan jalur ini dipakai.
     */
    private function ensureWilayahResolvable(PatientsCache $patient, bool $phoneContactException = false): void
    {
        if ($phoneContactException) {
            return;
        }

        // 'manual' (revisi Bu Kadis) = staf sudah sadar mengklaim pasien ini secara eksplisit
        // lewat PATCH /patients/{id}/override-puskesmas -- lebih meyakinkan daripada resolusi
        // otomatis manapun, jadi ikut dianggap "cukup jelas" untuk ditugaskan.
        $wilayahCukupJelas = $patient->wilayah_status === 'resolved'
            || $patient->puskesmas_resolution_method === 'kecamatan_fallback'
            || $patient->puskesmas_resolution_method === 'manual';

        if (! $wilayahCukupJelas) {
            throw ValidationException::withMessages([
                'patient' => ['Wilayah pasien belum resolved (bukan resolved maupun kecamatan_fallback) — tidak bisa ditugaskan ke kader.'],
            ]);
        }

        // Bisa terjadi WALAU wilayah_status=resolved: desa sudah match tapi desa.puskesmas_id
        // itu sendiri belum di-assign Dinkes (lihat produli:import-desa-puskesmas) — tanpa ini,
        // puskesmas_id_snapshot (NOT NULL) tidak punya nilai untuk disimpan.
        if ($patient->puskesmas_id === null) {
            throw ValidationException::withMessages([
                'patient' => ['Puskesmas pasien belum ter-assign — lengkapi lewat produli:import-desa-puskesmas dulu.'],
            ]);
        }
    }

    /**
     * "Validasi ketersediaan kader" (§3) diinterpretasikan sebagai: kader aktif, dari puskesmas
     * yang sama dengan pasien, dan belum punya assignment aktif untuk pasien yang sama (cegah
     * dobel-assign). Dokumen tidak memberi aturan kapasitas per hari, jadi tidak ditambahkan.
     *
     * $phoneContactException: cek "kader sepuskesmas dengan pasien" DI-SKIP -- patient->puskesmas_id
     * memang null di jalur ini (lokasi tidak diketahui), tidak ada apa pun yang bisa dibandingkan.
     * Kader tetap wajib aktif + belum ada assignment aktif ganda, itu tidak berubah.
     */
    private function ensureKaderAvailable(Kader $kader, PatientsCache $patient, bool $phoneContactException = false): void
    {
        if (! $kader->status_aktif) {
            throw ValidationException::withMessages([
                'kader' => ['Kader tidak aktif.'],
            ]);
        }

        if (! $phoneContactException && $kader->puskesmas_id !== $patient->puskesmas_id) {
            throw ValidationException::withMessages([
                'kader' => ['Kader bukan dari puskesmas yang sama dengan pasien.'],
            ]);
        }

        $sudahDitugaskan = VisitAssignment::where('patient_id', $patient->id)
            ->where('kader_id', $kader->id)
            ->whereIn('status', ['pending', 'in_progress'])
            ->exists();

        if ($sudahDitugaskan) {
            throw ValidationException::withMessages([
                'kader' => ['Pasien ini sudah punya assignment aktif ke kader yang sama.'],
            ]);
        }
    }

    /**
     * Varian ensureKaderAvailable() KHUSUS assignMultipleDates() -- guard "sudah punya
     * assignment aktif" SENGAJA TIDAK diikutkan (itu justru tujuan fitur multi-tanggal), diganti
     * guard tabrakan tanggal PERSIS (lihat docblock assignMultipleDates()).
     *
     * @param  string[]  $scheduledDates
     */
    private function ensureKaderAvailableForMultipleDates(Kader $kader, PatientsCache $patient, array $scheduledDates, bool $phoneContactException = false): void
    {
        if (! $kader->status_aktif) {
            throw ValidationException::withMessages([
                'kader' => ['Kader tidak aktif.'],
            ]);
        }

        if (! $phoneContactException && $kader->puskesmas_id !== $patient->puskesmas_id) {
            throw ValidationException::withMessages([
                'kader' => ['Kader bukan dari puskesmas yang sama dengan pasien.'],
            ]);
        }

        // whereIn('scheduled_date', ...) TIDAK bisa dipakai langsung -- meski kolomnya date-only,
        // beberapa driver (SQLite, konsisten dgn temuan komentar NotificationService::
        // scheduleUpcomingReminders() soal whereDate() vs whereIn raw utk kolom ini) menyimpan
        // nilainya dgn komponen waktu ("2026-08-31 00:00:00"), jadi perbandingan string PERSIS
        // gagal cocok dgn 'Y-m-d' polos. DATE(...) menormalkan kedua sisi, portable MySQL+SQLite.
        $existingDates = VisitAssignment::where('patient_id', $patient->id)
            ->where('kader_id', $kader->id)
            ->whereIn('status', ['pending', 'in_progress'])
            ->whereIn(DB::raw('DATE(scheduled_date)'), $scheduledDates)
            ->pluck('scheduled_date')
            ->map(fn ($d) => $d->toDateString())
            ->all();

        if ($existingDates !== []) {
            throw ValidationException::withMessages([
                'scheduled_dates' => ['Sudah ada penugasan aktif untuk kader ini di tanggal: '.implode(', ', $existingDates).'.'],
            ]);
        }
    }

    /**
     * Query list assignment ter-scope role (docs/planning/02 §7) -- dipakai VisitAssignmentController
     * (list) dan DashboardService (hitung visits_per_status), supaya aturan scope-nya satu tempat.
     *
     * @return Builder<VisitAssignment>
     */
    public function scopedQuery(User $user): Builder
    {
        if (DataScope::isFullAccess($user)) {
            return VisitAssignment::query();
        }

        if (DataScope::isKaderOnly($user)) {
            $kader = $user->kader;

            if ($kader === null) {
                return VisitAssignment::query()->whereRaw('1 = 0');
            }

            // Assignment yang dia dampingi (§16) ikut muncul di daftar tugasnya sendiri --
            // bukan cuma yang dia jadi kader_id (primer). Resource yang menandai peran mana
            // (role_in_assignment: primary|companion) lewat relasi companions yang di-eager-load.
            return VisitAssignment::query()->where(function (Builder $query) use ($kader) {
                $query->where('kader_id', $kader->id)
                    ->orWhereHas('companions', fn (Builder $q) => $q->where('kader_id', $kader->id));
            });
        }

        if (DataScope::isTenagaKesehatanOnly($user)) {
            $tenagaKesehatan = $user->tenagaKesehatan;

            if ($tenagaKesehatan === null) {
                return VisitAssignment::query()->whereRaw('1 = 0');
            }

            // Tidak ada konsep companion untuk tenaga_kesehatan (itu cuma untuk kader
            // pendamping/kunjungan berombongan kader) -- cukup assignment miliknya sendiri.
            return VisitAssignment::query()->where('tenaga_kesehatan_id', $tenagaKesehatan->id);
        }

        return $user->puskesmas_id !== null
            ? VisitAssignment::query()->where('puskesmas_id_snapshot', $user->puskesmas_id)
            : VisitAssignment::query()->whereRaw('1 = 0');
    }

    /**
     * Monitoring kunjungan (revisi Bu Kadis, dashboard/kunjungan monitoring) -- summary status
     * (termasuk 'overdue': scheduled_date SUDAH LEWAT tapi masih pending/in_progress, alias
     * "tenggat lewat") + breakdown per desa (berapa kunjungan & siapa petugasnya). SAMA scope
     * dengan scopedQuery() (kader/nakes: cuma assignment miliknya sendiri -- ringkasan 1 orang,
     * tidak terlalu berguna tapi tidak salah; admin_puskesmas/pj_prolanis: puskesmas sendiri;
     * super_admin: semua ATAU satu puskesmas kalau $puskesmasId diisi).
     *
     * @return array{summary: array{pending: int, in_progress: int, completed: int, cancelled: int, overdue: int}, per_desa: array<int, array{desa_id: int, desa_nama: string, total: int, pending: int, in_progress: int, completed: int, petugas: array<int, string>}>}
     */
    public function monitoringSummary(User $user, ?int $puskesmasId = null): array
    {
        $query = $this->scopedQuery($user);
        if ($puskesmasId !== null && DataScope::isFullAccess($user)) {
            $query->where('puskesmas_id_snapshot', $puskesmasId);
        }

        $statusCounts = (clone $query)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $overdueCount = (clone $query)
            ->whereIn('status', ['pending', 'in_progress'])
            ->whereDate('scheduled_date', '<', now()->toDateString())
            ->count();

        $summary = [
            'pending' => (int) ($statusCounts['pending'] ?? 0),
            'in_progress' => (int) ($statusCounts['in_progress'] ?? 0),
            'completed' => (int) ($statusCounts['completed'] ?? 0),
            'cancelled' => (int) ($statusCounts['cancelled'] ?? 0),
            'overdue' => $overdueCount,
        ];

        // BUG NYATA (laporan user: "Selesai" di $summary = 11, tapi tabel per-desa cuma 3 baris
        // yang jumlahnya tidak sampai 11 -- sangat membingungkan, terlihat seperti data hilang).
        // Sebelumnya JOIN 'desa' (INNER) diam-diam MEMBUANG baris yang patients_cache.desa_id
        // null/belum resolved dari tabel ini -- summary di atas TIDAK pakai join itu (hitung
        // SEMUA assignment tanpa syarat wilayah), jadi 2 sumber angka yang seharusnya konsisten
        // (total kunjungan) malah beda tanpa penjelasan apa pun ke user. Sekarang LEFT JOIN +
        // dikelompokkan eksplisit ke bucket "Desa Tidak Dikenali" (id sentinel 0, tidak akan
        // pernah bentrok dgn PK 'desa' asli yang auto_increment mulai dari 1) supaya SEMUA
        // kunjungan dalam scope ini ikut tampil & totalnya balik konsisten dgn $summary.
        $rows = (clone $query)
            ->join('patients_cache', 'patients_cache.id', '=', 'visit_assignments.patient_id')
            ->leftJoin('desa', 'desa.id', '=', 'patients_cache.desa_id')
            ->leftJoin('kader', 'kader.id', '=', 'visit_assignments.kader_id')
            ->leftJoin('users as kader_user', 'kader_user.id', '=', 'kader.user_id')
            ->leftJoin('tenaga_kesehatan', 'tenaga_kesehatan.id', '=', 'visit_assignments.tenaga_kesehatan_id')
            ->leftJoin('users as tk_user', 'tk_user.id', '=', 'tenaga_kesehatan.user_id')
            ->selectRaw('desa.id as desa_id, desa.nama as desa_nama, visit_assignments.status as status, COALESCE(kader_user.name, tk_user.name) as petugas_nama')
            ->get();

        $grouped = [];
        foreach ($rows as $row) {
            $desaId = $row->desa_id !== null ? (int) $row->desa_id : 0;
            $desaNama = $row->desa_id !== null ? $row->desa_nama : 'Desa Tidak Dikenali';

            if (! isset($grouped[$desaId])) {
                $grouped[$desaId] = [
                    'desa_id' => $desaId,
                    'desa_nama' => $desaNama,
                    'total' => 0,
                    'pending' => 0,
                    'in_progress' => 0,
                    'completed' => 0,
                    'petugas' => [],
                ];
            }

            $grouped[$desaId]['total']++;
            if (isset($grouped[$desaId][$row->status])) {
                $grouped[$desaId][$row->status]++;
            }
            if ($row->petugas_nama !== null && ! in_array($row->petugas_nama, $grouped[$desaId]['petugas'], true)) {
                $grouped[$desaId]['petugas'][] = $row->petugas_nama;
            }
        }

        return [
            'summary' => $summary,
            'per_desa' => array_values($grouped),
        ];
    }
}
