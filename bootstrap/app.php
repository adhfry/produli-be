<?php

use App\Exceptions\InvalidAuthTokenException;
use App\Exceptions\MustChangePasswordException;
use App\Exceptions\OnboardingRequiredException;
use App\Http\Middleware\EnsureOnboardingCompleted;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'password.changed' => EnsurePasswordChanged::class,
            'onboarding.completed' => EnsureOnboardingCompleted::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            return match (true) {
                $e instanceof ValidationException => ApiResponse::error(
                    'Data yang dikirim tidak valid',
                    422,
                    $e->errors(),
                ),
                $e instanceof AuthenticationException => ApiResponse::error(
                    'Anda perlu login untuk mengakses resource ini',
                    401,
                ),
                $e instanceof InvalidAuthTokenException => ApiResponse::error(
                    $e->getMessage(),
                    401,
                ),
                // Kode 'MUST_CHANGE_PASSWORD' di data -- dipakai frontend buat redirect otomatis
                // ke halaman ganti password (docs/planning/02, siklus password).
                $e instanceof MustChangePasswordException => ApiResponse::error(
                    $e->getMessage(),
                    403,
                    null,
                    ['code' => 'MUST_CHANGE_PASSWORD'],
                ),
                // Kode 'ONBOARDING_REQUIRED' di data -- dipakai frontend buat redirect otomatis
                // ke halaman /onboarding (docs/planning/02 §14).
                $e instanceof OnboardingRequiredException => ApiResponse::error(
                    $e->getMessage(),
                    403,
                    null,
                    ['code' => 'ONBOARDING_REQUIRED'],
                ),
                $e instanceof AuthorizationException => ApiResponse::error(
                    $e->getMessage() ?: 'Anda tidak memiliki akses untuk melakukan aksi ini',
                    403,
                ),
                $e instanceof ModelNotFoundException => ApiResponse::error(
                    'Data tidak ditemukan',
                    404,
                ),
                $e instanceof NotFoundHttpException => ApiResponse::error(
                    'Resource tidak ditemukan',
                    404,
                ),
                $e instanceof MethodNotAllowedHttpException => ApiResponse::error(
                    'Metode HTTP tidak diizinkan untuk endpoint ini',
                    405,
                ),
                $e instanceof TooManyRequestsHttpException => ApiResponse::error(
                    'Terlalu banyak permintaan, coba lagi nanti',
                    429,
                ),
                $e instanceof HttpExceptionInterface => ApiResponse::error(
                    $e->getMessage() ?: 'Terjadi kesalahan',
                    $e->getStatusCode(),
                ),
                default => ApiResponse::error(
                    config('app.debug') ? $e->getMessage() : 'Terjadi kesalahan pada server',
                    500,
                ),
            };
        });
    })->create();
