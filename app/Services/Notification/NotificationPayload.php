<?php

namespace App\Services\Notification;

/**
 * Payload generik untuk satu notifikasi, dipakai lintas channel (database/push, email, WA) dan
 * lintas pemicu (reminder kunjungan, sync SiLAKES selesai, update pasien, kunjungan mendesak).
 * `data` adalah bentuk mentah yang disimpan ke tabel `notifications` (channel database) --
 * pertahankan shape yang sudah dites (`type`, `assignment_id`, dst.) kalau membuat payload
 * untuk event yang sudah ada, jangan ubah tanpa cek NotificationResource/frontend formatNotification().
 */
final class NotificationPayload
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $type,
        public readonly string $title,
        public readonly string $body,
        public readonly array $data = [],
        public readonly ?string $imageUrl = null,
    ) {}
}
