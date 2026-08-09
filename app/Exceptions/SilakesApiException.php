<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Dilempar SilakesApiClient untuk kegagalan HTTP (bukan connection-level, itu tetap
 * Illuminate\Http\Client\ConnectionException bawaan Laravel — biar retry job otomatis jalan
 * lewat mekanisme queue standar). $statusCode dipakai SyncFieldUpdateToSilakesJob untuk
 * membedakan error yang PANTAS di-retry (5xx) vs yang TIDAK (4xx — retry tidak akan membantu).
 */
class SilakesApiException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $statusCode = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function isClientError(): bool
    {
        return $this->statusCode !== null && $this->statusCode >= 400 && $this->statusCode < 500;
    }
}
