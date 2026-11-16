<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Silakes\SilakesEventService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/integration/silakes-events -- SiLAKES mendorong keputusan staf Labkesda atas usulan
 * dari PRODULI (pendaftaran pasien baru, perubahan data) seketika. Gerbangnya tanda tangan HMAC
 * (VerifySilakesSignature), bukan login. Event yang sama boleh datang berulang (SiLAKES retry);
 * yang kedua dijawab 200 tanpa diproses ulang.
 */
class SilakesEventController extends Controller
{
    public function store(Request $request, SilakesEventService $events): JsonResponse
    {
        $event = $request->validate([
            'event_id' => ['required', 'string', 'max:64'],
            'event' => ['required', 'string', 'max:60'],
            'data' => ['required', 'array'],
        ]);

        $baru = $events->terima($event);

        return ApiResponse::success(['diproses' => $baru], $baru ? 'Event diterima.' : 'Event sudah pernah diterima.');
    }
}
