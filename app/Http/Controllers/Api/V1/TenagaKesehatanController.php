<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\TenagaKesehatan\RegisterTenagaKesehatanRequest;
use App\Http\Requests\TenagaKesehatan\UpdateTenagaKesehatanProfileRequest;
use App\Http\Requests\TenagaKesehatan\UpdateTenagaKesehatanRequest;
use App\Http\Resources\TenagaKesehatanResource;
use App\Models\TenagaKesehatan;
use App\Models\VisitReport;
use App\Services\Auth\AdminPasswordResetService;
use App\Services\Silakes\SilakesApiClient;
use App\Services\TenagaKesehatan\TenagaKesehatanService;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Mirror persis KaderController (list/store/setStatus) -- lihat docblock KaderService.
 * Self-service (profil sendiri, mode /app) ditambahkan revisi Bu Kadis PMO -- tenaga_kesehatan
 * sekarang ikut kunjungan lapangan seperti kader (lihat CareAssignmentService::
 * assignTenagaKesehatan()), jadi butuh /app juga, bukan cuma dashboard staf.
 */
class TenagaKesehatanController extends Controller
{
    public function __construct(private readonly TenagaKesehatanService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TenagaKesehatan::class);

        $paginator = $this->service->scopedQuery($request->user())
            ->with(['user', 'puskesmas'])
            ->when(
                $request->user()->hasRole('super_admin') && $request->filled('puskesmas_id'),
                fn ($query) => $query->where('puskesmas_id', $request->integer('puskesmas_id'))
            )
            ->when(
                $request->filled('status_aktif'),
                fn ($query) => $query->where('status_aktif', $request->boolean('status_aktif'))
            )
            ->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success([
            'items' => TenagaKesehatanResource::collection($paginator),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function store(RegisterTenagaKesehatanRequest $request): JsonResponse
    {
        $this->authorize('create', TenagaKesehatan::class);

        $tenagaKesehatan = $this->service->register($request->user(), $request->validated());
        $tenagaKesehatan->load(['user', 'puskesmas']);

        return ApiResponse::success(new TenagaKesehatanResource($tenagaKesehatan), 'Tenaga kesehatan berhasil didaftarkan', 201);
    }

    public function setStatus(Request $request, TenagaKesehatan $tenagaKesehatan): JsonResponse
    {
        $this->authorize('update', $tenagaKesehatan);

        $validated = $request->validate([
            'status_aktif' => ['required', 'boolean'],
        ]);

        $updated = $this->service->setActive($tenagaKesehatan, $validated['status_aktif']);
        $updated->load(['user', 'puskesmas']);

        $message = $validated['status_aktif'] ? 'Tenaga kesehatan berhasil diaktifkan' : 'Tenaga kesehatan berhasil dinonaktifkan';

        return ApiResponse::success(new TenagaKesehatanResource($updated), $message);
    }

    public function update(UpdateTenagaKesehatanRequest $request, TenagaKesehatan $tenagaKesehatan): JsonResponse
    {
        $this->authorize('update', $tenagaKesehatan);

        $updated = $this->service->update($request->user(), $tenagaKesehatan, $request->validated());

        return ApiResponse::success(new TenagaKesehatanResource($updated), 'Data tenaga kesehatan berhasil diperbarui');
    }

    public function destroy(TenagaKesehatan $tenagaKesehatan): JsonResponse
    {
        $this->authorize('delete', $tenagaKesehatan);

        $this->service->delete($tenagaKesehatan);

        return ApiResponse::success(null, 'Tenaga kesehatan berhasil dihapus');
    }

    /**
     * Reset password, dipicu ADMINISTRATOR (super_admin saja) -- mirror persis
     * KaderController::resetPassword().
     */
    public function resetPassword(Request $request, TenagaKesehatan $tenagaKesehatan, AdminPasswordResetService $resetService): JsonResponse
    {
        if (! $request->user()->hasRole('super_admin')) {
            throw new AuthorizationException('Hanya super_admin yang bisa mereset password.');
        }

        $tenagaKesehatan->loadMissing('user');

        if ($tenagaKesehatan->user === null) {
            throw ValidationException::withMessages([
                'tenaga_kesehatan' => ['Tenaga kesehatan ini tidak punya akun user yang valid.'],
            ]);
        }

        $resetService->reset($tenagaKesehatan->user, $request->user());

        return ApiResponse::success(null, "Password berhasil direset, email berisi password baru sudah dikirim ke {$tenagaKesehatan->user->email}.");
    }

    /**
     * Self-service: tenaga_kesehatan baca profilnya SENDIRI -- mirror persis
     * KaderController::showProfile().
     */
    public function showProfile(Request $request): JsonResponse
    {
        $tenagaKesehatan = $request->user()->tenagaKesehatan;

        if ($tenagaKesehatan === null) {
            throw ValidationException::withMessages([
                'tenaga_kesehatan' => ['Akun Anda belum punya profil tenaga kesehatan.'],
            ]);
        }

        $this->authorize('viewOwnProfile', $tenagaKesehatan);

        return ApiResponse::success(new TenagaKesehatanResource($tenagaKesehatan->load(['user', 'puskesmas', 'pj'])));
    }

    /**
     * Self-service: tenaga_kesehatan update profilnya SENDIRI (no_wa/alamat/gender/tgl_lahir) --
     * mirror persis KaderController::updateProfile().
     */
    public function updateProfile(UpdateTenagaKesehatanProfileRequest $request): JsonResponse
    {
        $tenagaKesehatan = $request->user()->tenagaKesehatan;

        if ($tenagaKesehatan === null) {
            throw ValidationException::withMessages([
                'tenaga_kesehatan' => ['Akun Anda belum punya profil tenaga kesehatan.'],
            ]);
        }

        $this->authorize('updateOwnProfile', $tenagaKesehatan);

        $updated = $this->service->updateOwnProfile($tenagaKesehatan, $request->validated());

        return ApiResponse::success(new TenagaKesehatanResource($updated), 'Profil berhasil diperbarui');
    }

    /**
     * Self-service: riwayat pengajuan pembaruan data pasien yang PERNAH DIAJUKAN
     * tenaga_kesehatan ini sendiri saat kunjungan -- mirror persis
     * KaderController::updateRequests(), filter tenaga_kesehatan_id bukan kader_id.
     */
    public function updateRequests(Request $request, SilakesApiClient $client): JsonResponse
    {
        $tenagaKesehatan = $request->user()->tenagaKesehatan;

        if ($tenagaKesehatan === null) {
            throw ValidationException::withMessages([
                'tenaga_kesehatan' => ['Akun Anda belum punya profil tenaga kesehatan.'],
            ]);
        }

        $paginator = VisitReport::query()
            ->whereHas('assignment', fn ($q) => $q->where('tenaga_kesehatan_id', $tenagaKesehatan->id))
            ->where(fn ($q) => $q->whereNotNull('patient_field_updates')->orWhereNotNull('latitude'))
            ->with('assignment.patient')
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        $reports = collect($paginator->items());

        $historyByPatient = $reports
            ->pluck('assignment.patient.external_patient_id')
            ->unique()
            ->filter()
            ->mapWithKeys(function (int $externalPatientId) use ($client) {
                try {
                    $body = $client->getPembaruanLapanganHistory($externalPatientId);

                    return [$externalPatientId => collect($body['data'] ?? [])->groupBy('produli_visit_id')];
                } catch (Throwable $e) {
                    report($e);

                    return [$externalPatientId => collect()];
                }
            });

        $data = $reports->map(function (VisitReport $report) use ($historyByPatient) {
            $patient = $report->assignment->patient;
            /** @var Collection $fieldsForThisVisit */
            $fieldsForThisVisit = $historyByPatient->get($patient->external_patient_id, collect())
                ->get($report->id, collect());

            return [
                'visit_report_id' => $report->id,
                'patient_id' => $patient->id,
                'patient_nama' => $patient->nama,
                'kunjungan_tanggal' => $report->created_at?->toIso8601String(),
                'push_status' => $report->sync_status,
                'push_error' => $report->sync_error,
                'fields' => $fieldsForThisVisit->map(fn ($row) => [
                    'kategori' => $row['kategori'],
                    'field_name' => $row['field_name'],
                    'old_value' => $row['old_value'],
                    'new_value' => $row['new_value'],
                    'status' => $row['status'],
                    'reviewed_at' => $row['reviewed_at'],
                    'catatan_reviewer' => $row['catatan_reviewer'],
                ])->values(),
            ];
        })->values();

        return ApiResponse::success([
            'items' => $data,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }
}
