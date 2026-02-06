<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use SocialiteProviders\Manager\SocialiteWasCalled;
use Illuminate\Auth\Notifications\ResetPassword;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        ResetPassword::createUrlUsing(function ($notifiable, string $token) {
            $frontend = rtrim(config('app.frontend_url'), '/');

            if (!$frontend) {
                // fail-safe biar ketahuan kalau env kosong
                throw new \Exception('FRONTEND_URL is not set');
            }

            $email = urlencode($notifiable->getEmailForPasswordReset());

            return "{$frontend}/reset-password?token={$token}&email={$email}";
        });

        // (punya kamu) Socialite Discord
        Event::listen(function (SocialiteWasCalled $event) {
            $event->extendSocialite('discord', \SocialiteProviders\Discord\Provider::class);
        });
    }
}
