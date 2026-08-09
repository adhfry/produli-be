<?php

namespace App\Services\Announcement;

use App\Models\SystemAnnouncement;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Pengumuman Sistem (docs/planning/02 §13) -- global, tidak ada scoping per puskesmas/kader
 * (semua role login berhak baca), cuma super_admin yang boleh posting.
 */
class AnnouncementService
{
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return SystemAnnouncement::query()
            ->with('postedBy')
            ->latest()
            ->paginate($perPage);
    }

    /**
     * @param  array{title: string, description: string, type: string}  $data
     */
    public function create(User $postedBy, array $data): SystemAnnouncement
    {
        return SystemAnnouncement::create([
            'title' => $data['title'],
            'description' => $data['description'],
            'type' => $data['type'],
            'posted_by' => $postedBy->id,
        ]);
    }
}
