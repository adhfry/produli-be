<?php

namespace App\DTO;

use Carbon\CarbonInterface;

final class TokenPair
{
    public function __construct(
        public readonly string $accessToken,
        public readonly string $rawRefreshToken,
        public readonly CarbonInterface $accessTokenExpiresAt,
        public readonly CarbonInterface $refreshTokenExpiresAt,
    ) {}
}
