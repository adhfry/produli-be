<?php

namespace App\Services\Visit\Validation;

use App\DTO\VisitValidationContext;
use App\DTO\VisitValidationResult;

/**
 * Kontrak Strategy Pattern untuk 1 layer dari 7-layer validation (docs/planning/02 §3) —
 * tiap layer independen, testable, dan bisa di-toggle sesuai kebijakan (Open/Closed Principle).
 */
interface VisitValidationLayer
{
    /**
     * Nama pendek layer, dipakai sebagai identitas di VisitValidationResult/logging.
     */
    public function name(): string;

    /**
     * Apakah layer ini aktif. Cuma FaceDetectionCheck yang punya feature-flag (§10);
     * layer lain selalu true.
     */
    public function isEnabled(): bool;

    public function validate(VisitValidationContext $context): VisitValidationResult;
}
