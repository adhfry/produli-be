<?php

namespace App\Http\Controllers\Api\V1;

use App\DTO\VisitValidationContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Visit\SubmitVisitReportRequest;
use App\Http\Requests\Visit\ValidateVisitReportRequest;
use App\Http\Resources\VisitReportResource;
use App\Models\VisitAssignment;
use App\Models\VisitReport;
use App\Services\Visit\VisitReportReviewService;
use App\Services\Visit\VisitReportService;
use App\Support\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class VisitReportController extends Controller
{
    public function __construct(
        private readonly VisitReportService $service,
        private readonly VisitReportReviewService $reviewService,
    ) {}

    /**
     * Submit laporan kunjungan kader (docs/planning/02 §3/§5) -- terima upload foto multipart,
     * validasi 7-layer (VisitValidationService) lalu simpan + push foto ke S3/MinIO
     * (VisitReportService). `expectedLatitude`/`expectedLongitude`/`geoStatus` SENGAJA diambil
     * dari data pasien di database (bukan dari input klien) -- kalau klien yang menentukan
     * "lokasi yang diharapkan", geofencing jadi tidak berarti apa-apa (klien bisa klaim di mana
     * saja lalu bilang itu lokasi yang benar).
     */
    public function store(SubmitVisitReportRequest $request): JsonResponse
    {
        $assignment = VisitAssignment::with(['patient', 'kader.user'])
            ->findOrFail($request->validated('assignment_id'));

        $this->authorize('submitReport', $assignment);

        $patient = $assignment->patient;

        $context = new VisitValidationContext(
            latitude: (float) $request->validated('latitude'),
            longitude: (float) $request->validated('longitude'),
            gpsAccuracyMeters: $request->filled('gps_accuracy_meters') ? (float) $request->validated('gps_accuracy_meters') : null,
            gpsCapturedAt: Carbon::parse($request->validated('gps_captured_at')),
            expectedLatitude: $patient->latitude !== null ? (float) $patient->latitude : null,
            expectedLongitude: $patient->longitude !== null ? (float) $patient->longitude : null,
            geoStatus: $patient->geo_status,
            photoPath: $request->file('photo')->getRealPath(),
            capturedLive: $request->boolean('captured_live'),
            isOffline: $request->boolean('is_offline'),
            clientSubmissionId: $request->input('client_submission_id'),
            faceDetectedClientSide: $request->has('face_detected_client_side')
                ? $request->boolean('face_detected_client_side')
                : null,
            kaderName: $assignment->kader->user->name,
        );

        $report = $this->service->submit(
            $assignment,
            $context,
            $request->validated('kondisi'),
            $request->validated('catatan'),
            $request->boolean('confirmed_patient_location'),
            $request->patientFieldUpdates(),
            $request->pemeriksaan(),
            // null = tidak dikirim sama sekali -> VisitReportService pre-fill dari
            // visit_assignment_companions (docs/planning/02 §16). Array (termasuk kosong) =
            // kader primer eksplisit mengoreksi kehadiran aktual.
            $request->hasAttendeeOverride() ? $request->attendeeKaderIds() : null,
        );
        $report->load('attendees.kader.user');

        return ApiResponse::success(new VisitReportResource($report), 'Laporan kunjungan berhasil disimpan', 201);
    }

    /**
     * PJ Prolanis menerima laporan kunjungan dari kader yang disupervisinya sendiri
     * (docs/planning/02 §11) -- pengakuan operasional, idempotent.
     */
    public function accept(VisitReport $visitReport): JsonResponse
    {
        $this->authorize('accept', $visitReport);

        $report = $this->reviewService->accept($visitReport, request()->user());
        $report->load('attendees.kader.user');

        return ApiResponse::success(new VisitReportResource($report), 'Laporan kunjungan berhasil diterima');
    }

    /**
     * Validasi final laporan kunjungan oleh super_admin (docs/planning/02 §11) -- boleh kapan
     * pun, tidak perlu menunggu PJ Prolanis menerima dulu, dan boleh diubah ulang.
     */
    public function validateReport(ValidateVisitReportRequest $request, VisitReport $visitReport): JsonResponse
    {
        $this->authorize('validateReport', VisitReport::class);

        $report = $this->reviewService->validateReport(
            $visitReport,
            $request->user(),
            $request->boolean('is_valid'),
            $request->input('note'),
        );
        $report->load('attendees.kader.user');

        return ApiResponse::success(new VisitReportResource($report), 'Validasi laporan kunjungan berhasil disimpan');
    }
}
