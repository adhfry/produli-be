<?php

namespace App\Providers;

use App\Services\Visit\Validation\Support\ExifReader;
use App\Services\Visit\Validation\Support\NativeExifReader;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ExifReader::class, NativeExifReader::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Backend API-only ini TIDAK punya route web 'password.reset' (default Laravel butuh
        // itu) -- link+isi email reset password dikustomisasi total di sini, arahkan ke frontend
        // (Nuxt), konsisten Bahasa Indonesia seperti AccountActivationMail.
        ResetPassword::toMailUsing(function ($notifiable, string $token) {
            $resetUrl = rtrim((string) config('app.frontend_url'), '/')
                .'/reset-password?token='.$token
                .'&email='.urlencode($notifiable->getEmailForPasswordReset());

            $expireMinutes = (int) config('auth.passwords.users.expire');

            return (new MailMessage())
                ->subject('Reset Password KOPIPU Smart')
                ->markdown('emails.reset-password', [
                    'resetUrl' => $resetUrl,
                    'expireMinutes' => $expireMinutes,
                    'userName' => $notifiable->name,
                ]);
        });
    }
}
