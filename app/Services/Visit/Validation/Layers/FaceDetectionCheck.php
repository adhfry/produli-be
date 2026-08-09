<?php

namespace App\Services\Visit\Validation\Layers;

use App\DTO\VisitValidationContext;
use App\DTO\VisitValidationResult;
use App\Services\Visit\Validation\VisitValidationLayer;

/**
 * Layer 6 — Face Detection (docs/planning/02 §3/§10). NON-AKTIF SECARA DEFAULT di v1 —
 * 6 layer lain sudah memberi anti-fraud yang kuat, face detection menambah kompleksitas untuk
 * kenaikan keamanan marginal (§10).
 *
 * PENTING kalau nanti diaktifkan: deteksi wajah dilakukan CLIENT-SIDE (browser/PWA), backend
 * di sini CUMA mempercayai boolean presence yang dikirim — TIDAK PERNAH menjalankan model
 * deteksi sendiri, dan JANGAN PERNAH simpan face embedding/template biometrik permanen atau
 * lakukan face matching/recognition identitas (kategori data pribadi spesifik, butuh consent
 * eksplisit terpisah menurut UU PDP No. 27/2022).
 */
class FaceDetectionCheck implements VisitValidationLayer
{
    public function name(): string
    {
        return 'face_detection';
    }

    public function isEnabled(): bool
    {
        return (bool) config('kopipu.validation.face_detection_enabled');
    }

    public function validate(VisitValidationContext $context): VisitValidationResult
    {
        if ($context->faceDetectedClientSide !== true) {
            return VisitValidationResult::fail($this->name(), 'Wajah tidak terdeteksi di foto.');
        }

        // Cuma boolean presence yang disimpan/diteruskan — bukan data biometrik.
        return VisitValidationResult::pass($this->name(), metadata: ['face_detected' => true]);
    }
}
