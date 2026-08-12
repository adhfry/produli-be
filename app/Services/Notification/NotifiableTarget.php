<?php

namespace App\Services\Notification;

use App\Models\User;

/**
 * Target penerima notifikasi -- satu user, semua user di 1 puskesmas, atau broadcast ke semua
 * user. Dipakai NotifyService untuk memutuskan siapa saja yang perlu diresolve jadi daftar
 * User sebelum fan-out per channel.
 */
final class NotifiableTarget
{
    private function __construct(
        public readonly string $scope,
        public readonly ?User $user = null,
        public readonly ?int $puskesmasId = null,
    ) {}

    public static function user(User $user): self
    {
        return new self('user', user: $user);
    }

    public static function puskesmas(int $puskesmasId): self
    {
        return new self('puskesmas', puskesmasId: $puskesmasId);
    }

    public static function broadcast(): self
    {
        return new self('broadcast');
    }
}
