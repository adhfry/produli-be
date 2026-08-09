<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Announcement\CreateAnnouncementRequest;
use App\Http\Resources\AnnouncementResource;
use App\Models\SystemAnnouncement;
use App\Services\Announcement\AnnouncementService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    public function __construct(private readonly AnnouncementService $service) {}

    /**
     * Semua role login berhak baca (docs/planning/02 §13) -- pengumuman global, tidak ada
     * scoping per puskesmas/kader.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SystemAnnouncement::class);

        $paginator = $this->service->paginate($request->integer('per_page', 20));

        return ApiResponse::success([
            'items' => AnnouncementResource::collection($paginator),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * super_admin only (docs/planning/02 §13).
     */
    public function store(CreateAnnouncementRequest $request): JsonResponse
    {
        $this->authorize('create', SystemAnnouncement::class);

        $announcement = $this->service->create($request->user(), $request->validated());
        $announcement->load('postedBy');

        return ApiResponse::success(new AnnouncementResource($announcement), 'Pengumuman berhasil dibuat', 201);
    }
}
