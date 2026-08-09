<?php

namespace App\Services\Visit\Validation\Layers;

use App\DTO\VisitValidationContext;
use App\DTO\VisitValidationResult;
use App\Services\Visit\Validation\VisitValidationLayer;

/**
 * Layer 3 — Live Camera (docs/planning/02 §3). Backend tidak bisa membuktikan 100% foto
 * diambil langsung dari kamera (bukan galeri) tanpa atestasi tingkat OS — layer ini
 * mempercayai penanda dari UI capture-only Nuxt (capturedLive), dipertajam oleh
 * ExifValidator (Layer 5) sebagai lapisan pertahanan kedua.
 */
class LiveCameraCheck implements VisitValidationLayer
{
    public function name(): string
    {
        return 'live_camera';
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function validate(VisitValidationContext $context): VisitValidationResult
    {
        if (! $context->capturedLive) {
            return VisitValidationResult::fail($this->name(), 'Foto harus diambil langsung dari kamera, bukan dari galeri.');
        }

        return VisitValidationResult::pass($this->name());
    }
}
