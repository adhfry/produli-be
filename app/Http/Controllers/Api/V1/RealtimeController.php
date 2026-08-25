<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Realtime\WebsocketTokenService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Token socket produli-wss (docs plan realtime -- lihat README produli-wss untuk kontrak
 * lengkap). Frontend minta token BARU tiap kali socket reconnect (token umur pendek, lihat
 * config('produli.realtime.token_ttl_seconds')), bukan sekali ambil dipakai berjam-jam.
 */
class RealtimeController extends Controller
{
    public function __construct(private readonly WebsocketTokenService $tokenService) {}

    public function token(Request $request): JsonResponse
    {
        return ApiResponse::success([
            'token' => $this->tokenService->issueFor($request->user()),
        ]);
    }
}
