<?php

namespace App\Services\Announcement;

use App\Models\AnnouncementRead;
use App\Models\SystemAnnouncement;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Pengumuman Sistem (docs/planning/02 §13) -- global secara DEFAULT (target_roles kosong),
 * tapi sekarang bisa ditarget ke role tertentu. Cuma super_admin yang boleh posting. Read-
 * tracking per user (announcement_reads) menggerbangi modal inbox lebar saat login pertama --
 * lihat AnnouncementController::unread()/markRead().
 */
class AnnouncementService
{
    /**
     * Daftar untuk halaman /dashboard (feed) MAUPUN /dashboard/pengumuman (pembuat, super_admin)
     * -- di-scope ke pengumuman yang ditarget ke user ini (isTargetedTo()), TERMASUK yang sudah
     * dibaca (beda dari unreadForUser(), ini daftar riwayat lengkap bukan cuma yang belum
     * dibaca). super_admin TIDAK dapat perlakuan khusus lihat-semua di sini -- kalau mereka
     * posting pengumuman yang ditarget cuma ke 'kader', mereka sendiri juga tidak akan melihatnya
     * di feed pribadinya (konsisten: target_roles benar-benar berarti "siapa yang melihat").
     */
    public function paginateForUser(User $user, int $perPage = 20, int $page = 1): LengthAwarePaginator
    {
        $roles = $user->getRoleNames()->all();
        $readIds = $this->readAnnouncementIds($user);

        // Filter target_roles dilakukan di PHP (bukan query JSON_CONTAINS di SQL) -- volume
        // pengumuman rendah (postingan manual super_admin, bukan data transaksional), dan
        // sintaks JSON MySQL vs SQLite (dipakai test suite) berbeda cukup jauh untuk kondisi
        // "null ATAU array kosong ATAU intersect" ini. get() dulu, baru filter+paginate manual.
        $matching = SystemAnnouncement::query()
            ->with('postedBy')
            ->latest()
            ->get()
            ->filter(fn (SystemAnnouncement $a) => $a->isTargetedTo($roles))
            ->values()
            ->each(fn (SystemAnnouncement $a) => $a->setAttribute('is_read', $readIds->contains($a->id)));

        return new LengthAwarePaginator(
            $matching->forPage($page, $perPage)->values(),
            $matching->count(),
            $perPage,
            $page,
        );
    }

    /**
     * Pengumuman yang ditarget ke role user ini DAN belum pernah dibaca -- sumber modal inbox
     * lebar saat login pertama. Terurut TERLAMA dulu (ascending) supaya urutan baca kronologis
     * (pengumuman lama yang terlewat tetap dilihat sebelum yang paling baru), bukan yang
     * terbaru "menutupi" yang lebih lama belum dibaca.
     *
     * @return Collection<int, SystemAnnouncement>
     */
    public function unreadForUser(User $user): Collection
    {
        $roles = $user->getRoleNames()->all();
        $readIds = $this->readAnnouncementIds($user);

        return SystemAnnouncement::query()
            ->with('postedBy')
            ->oldest()
            ->get()
            ->filter(fn (SystemAnnouncement $a) => $a->isTargetedTo($roles) && ! $readIds->contains($a->id))
            ->values();
    }

    public function markRead(User $user, SystemAnnouncement $announcement): void
    {
        AnnouncementRead::firstOrCreate(
            ['user_id' => $user->id, 'announcement_id' => $announcement->id],
            ['read_at' => now()],
        );
    }

    /**
     * @return Collection<int, int>
     */
    private function readAnnouncementIds(User $user): Collection
    {
        return AnnouncementRead::where('user_id', $user->id)->pluck('announcement_id');
    }

    /**
     * @param  array{title: string, description: string, urgency: string, icon?: ?string,
     *                color?: ?string, image_url?: ?string, button_label?: ?string,
     *                button_url?: ?string, target_roles?: ?array<int, string>}  $data
     */
    public function create(User $postedBy, array $data): SystemAnnouncement
    {
        return SystemAnnouncement::create([
            'title' => $data['title'],
            'description' => $data['description'],
            'urgency' => $data['urgency'],
            'icon' => $data['icon'] ?? null,
            'color' => $data['color'] ?? null,
            'image_url' => $data['image_url'] ?? null,
            'button_label' => $data['button_label'] ?? null,
            'button_url' => $data['button_url'] ?? null,
            'target_roles' => $data['target_roles'] ?? null,
            'posted_by' => $postedBy->id,
        ]);
    }

    public function delete(SystemAnnouncement $announcement): void
    {
        $announcement->delete();
    }
}
