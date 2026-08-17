<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AnnouncementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'urgency' => $this->urgency,
            'icon' => $this->icon,
            'color' => $this->color,
            'image_url' => $this->image_url,
            'button_label' => $this->button_label,
            'button_url' => $this->button_url,
            // null/[] = semua role -- lihat SystemAnnouncement::isTargetedTo().
            'target_roles' => $this->target_roles,
            // Cuma terisi lewat AnnouncementService::paginateForUser()/unreadForUser() (di-set
            // manual via setAttribute(), bukan kolom DB) -- null kalau Resource ini dipakai di
            // luar dua jalur itu (mis. response store()), frontend WAJIB treat null sebagai
            // "tidak diketahui", bukan otomatis false.
            'is_read' => $this->getAttribute('is_read'),
            'posted_by' => $this->whenLoaded('postedBy', fn () => $this->postedBy ? [
                'id' => $this->postedBy->id,
                'name' => $this->postedBy->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
