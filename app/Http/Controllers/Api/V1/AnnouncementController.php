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
     * Daftar pengumuman untuk feed /dashboard MAUPUN halaman pembuat /dashboard/pengumuman --
     * di-scope ke target_roles user yang login (docs/planning/02 §13), termasuk yang sudah
     * dibaca (is_read per item).
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SystemAnnouncement::class);

        $paginator = $this->service->paginateForUser(
            $request->user(),
            $request->integer('per_page', 20),
            $request->integer('page', 1),
        );

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
     * Sumber modal inbox lebar saat login pertama -- pengumuman yang ditarget ke user ini DAN
     * belum pernah dibaca. Tidak dipaginasi (volume kecil by design -- kalau user sampai punya
     * puluhan pengumuman belum dibaca, itu masalah operasional lain, bukan yang perlu digerbangi
     * paginasi di sini).
     */
    public function unread(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SystemAnnouncement::class);

        $items = $this->service->unreadForUser($request->user());

        return ApiResponse::success([
            'items' => AnnouncementResource::collection($items),
        ]);
    }

    /**
     * Ditandai SETELAH user benar-benar melihat/menutup pengumuman di modal inbox (frontend) --
     * bukan otomatis saat index()/unread() dipanggil. Idempotent -- baca ulang tidak error.
     */
    public function markRead(Request $request, SystemAnnouncement $announcement): JsonResponse
    {
        $this->authorize('viewAny', SystemAnnouncement::class);

        $this->service->markRead($request->user(), $announcement);

        return ApiResponse::success(null, 'Pengumuman ditandai sudah dibaca');
    }

    /**
     * super_admin only (docs/planning/02 §13) -- halaman /dashboard/pengumuman.
     */
    public function store(CreateAnnouncementRequest $request): JsonResponse
    {
        $this->authorize('create', SystemAnnouncement::class);

        $announcement = $this->service->create($request->user(), $request->validated());
        $announcement->load('postedBy');

        return ApiResponse::success(new AnnouncementResource($announcement), 'Pengumuman berhasil dibuat', 201);
    }

    /**
     * super_admin only -- hapus pengumuman yang salah/sudah tidak relevan. announcement_reads
     * ikut terhapus (cascadeOnDelete migration), bukan masalah -- itu cuma jejak baca, bukan
     * data yang perlu dipertahankan setelah pengumumannya sendiri dihapus.
     */
    public function destroy(SystemAnnouncement $announcement): JsonResponse
    {
        $this->authorize('delete', $announcement);

        $this->service->delete($announcement);

        return ApiResponse::success(null, 'Pengumuman berhasil dihapus');
    }
}
