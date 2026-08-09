<?php

namespace App\DTO;

/**
 * Hasil 1 layer validasi. WatermarkGenerator selalu "passed" (bukan validator penolak) tapi
 * tetap pakai bentuk ini supaya seragam dengan layer lain — hasil olahannya (path foto
 * ber-watermark) dititipkan lewat $metadata.
 */
final class VisitValidationResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $layer,
        public readonly bool $passed,
        public readonly ?string $message = null,
        public readonly array $metadata = [],
        public readonly bool $skipped = false,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function pass(string $layer, ?string $message = null, array $metadata = []): self
    {
        return new self($layer, true, $message, $metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function fail(string $layer, string $message, array $metadata = []): self
    {
        return new self($layer, false, $message, $metadata);
    }

    public static function skipped(string $layer): self
    {
        return new self($layer, true, 'Layer dinonaktifkan (feature-flag).', [], skipped: true);
    }
}
