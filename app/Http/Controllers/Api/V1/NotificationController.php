<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * List & tandai-dibaca notifikasi/reminder milik user yang login (docs/planning/02 §8) --
 * lewat tabel `notifications` bawaan Laravel (Notifiable trait di User), diisi
 * NotificationService via DatabaseReminderChannel. Tanpa ini, reminder yang sudah dibuat tidak
 * pernah bisa diambil frontend.
 */
class NotificationController extends Controller
{
    /**
     * Selalu di-scope ke user sendiri lewat $request->user()->notifications() -- tidak ada
     * jalur untuk lihat notifikasi user lain.
     */
    public function index(Request $request): JsonResponse
    {
        $query = $request->boolean('unread')
            ? $request->user()->unreadNotifications()
            : $request->user()->notifications();

        $paginator = $query->paginate($request->integer('per_page', 20));

        return ApiResponse::success([
            'items' => NotificationResource::collection($paginator),
            'unread_count' => $request->user()->unreadNotifications()->count(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * Dicari HANYA dalam notifikasi milik user yang login (findOrFail di scope relasi
     * notifications(), bukan model binding global) supaya user tidak bisa menandai/menebak ID
     * notifikasi user lain. markAsRead() bawaan Laravel sudah idempotent (no-op kalau sudah dibaca).
     */
    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $record = $request->user()->notifications()->findOrFail($id);
        $record->markAsRead();

        return ApiResponse::success(new NotificationResource($record->fresh()), 'Notifikasi ditandai sudah dibaca');
    }
}
