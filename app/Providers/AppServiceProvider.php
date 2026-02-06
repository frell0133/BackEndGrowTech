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
        // ✅ Reset link diarahkan ke Next.js
        ResetPassword::createUrlUsing(function ($notifiable, string $token) {
            $frontend = rtrim((string) env('FRONTEND_URL', 'https://frontendgrowtechtesting1-production.up.railway.app'), '/');
            $email = urlencode($notifiable->getEmailForPasswordReset());

            // halaman FE kamu:
            return "{$frontend}/reset-password?token={$token}&email={$email}";
        });

        // (punya kamu) Socialite Discord
        Event::listen(function (SocialiteWasCalled $event) {
            $event->extendSocialite('discord', \SocialiteProviders\Discord\Provider::class);
        });
    }
}
