<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shaping tabel `notifications` bawaan Laravel (docs/planning/02 §8) -- `type` di sini diambil
 * dari data['type'] (mis. 'visit_reminder', lihat VisitReminderNotification::toDatabase()),
 * BUKAN nama class PHP mentah di kolom `type` aslinya -- lebih berguna buat frontend.
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->data['type'] ?? null,
            'data' => $this->data,
            'is_read' => $this->read(),
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
