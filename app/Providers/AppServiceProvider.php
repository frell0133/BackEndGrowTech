<?php

namespace App\Providers;

use App\Models\AdminPermission;
use App\Models\AdminRole;
use App\Models\Banner;
use App\Models\Category;
use App\Models\Delivery;
use App\Models\DiscountCampaign;
use App\Models\DiscountCampaignTarget;
use App\Models\Faq;
use App\Models\License;
use App\Models\Order;
use App\Models\Page;
use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Models\Popup;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductStockLog;
use App\Models\ReferralSetting;
use App\Models\Setting;
use App\Models\SubCategory;
use App\Models\User;
use App\Models\Voucher;
use App\Models\WalletTopup;
use App\Models\WithdrawRequest;
use App\Observers\AdminCrudAuditObserver;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureRateLimiters();

        ResetPassword::createUrlUsing(function ($notifiable, string $token) {
            $frontend = rtrim((string) config('app.frontend_url'), '/');

            if (!$frontend) {
                throw new \Exception('FRONTEND_URL is not set');
            }

            $email = urlencode($notifiable->getEmailForPasswordReset());
            return "{$frontend}/reset-password?token={$token}&email={$email}";
        });

        Event::listen(function (SocialiteWasCalled $event) {
            $event->extendSocialite('discord', \SocialiteProviders\Discord\Provider::class);
        });

        foreach ($this->auditedModels() as $modelClass) {
            $modelClass::observe(AdminCrudAuditObserver::class);
        }
    }

    private function configureRateLimiters(): void
    {
        RateLimiter::for('public-content', fn (Request $request) => $this->apiLimit(
            120,
            $this->ipKey($request, 'public-content'),
            'Terlalu banyak request content publik.'
        ));

        RateLimiter::for('public-catalog', fn (Request $request) => $this->apiLimit(
            90,
            $this->ipKey($request, 'public-catalog'),
            'Terlalu banyak request katalog publik.'
        ));

        RateLimiter::for('shell-bootstrap', fn (Request $request) => $this->apiLimit(
            60,
            $this->userOrIpKey($request, 'shell-bootstrap'),
            'Terlalu banyak request bootstrap.'
        ));

        RateLimiter::for('auth-register', fn (Request $request) => $this->apiLimit(
            5,
            $this->ipEmailKey($request, 'auth-register'),
            'Terlalu banyak percobaan registrasi.'
        ));

        RateLimiter::for('auth-login', fn (Request $request) => $this->apiLimit(
            10,
            $this->ipEmailKey($request, 'auth-login'),
            'Terlalu banyak percobaan login.'
        ));

        RateLimiter::for('auth-social', fn (Request $request) => $this->apiLimit(
            12,
            $this->ipKey($request, 'auth-social'),
            'Terlalu banyak percobaan login sosial.'
        ));

        RateLimiter::for('auth-password', fn (Request $request) => $this->apiLimit(
            6,
            $this->ipEmailKey($request, 'auth-password'),
            'Terlalu banyak request reset password.'
        ));

        RateLimiter::for('otp-verify', fn (Request $request) => $this->apiLimit(
            20,
            $this->ipChallengeKey($request, 'otp-verify'),
            'Terlalu banyak percobaan verifikasi OTP.'
        ));

        RateLimiter::for('otp-resend', fn (Request $request) => $this->apiLimit(
            4,
            $this->ipChallengeKey($request, 'otp-resend'),
            'Terlalu banyak request kirim ulang OTP.'
        ));

        RateLimiter::for('payment-create', fn (Request $request) => $this->apiLimit(
            10,
            $this->userOrIpKey($request, 'payment-create:' . (string) $request->route('id')),
            'Terlalu banyak request pembuatan pembayaran.'
        ));

        RateLimiter::for('payment-status', fn (Request $request) => $this->apiLimit(
            30,
            $this->userOrIpKey($request, 'payment-status:' . (string) $request->route('id')),
            'Terlalu banyak polling status pembayaran.'
        ));

        RateLimiter::for('user-write-light', fn (Request $request) => $this->apiLimit(
            20,
            $this->userOrIpKey($request, 'user-write-light:' . $request->path()),
            'Terlalu banyak request perubahan data.'
        ));

        RateLimiter::for('admin-heavy', fn (Request $request) => $this->apiLimit(
            20,
            $this->userOrIpKey($request, 'admin-heavy:' . $request->path()),
            'Terlalu banyak request admin berat.'
        ));
    }

    private function apiLimit(int $perMinute, string $key, string $message): Limit
    {
        return Limit::perMinute($perMinute)
            ->by($key)
            ->response(function (Request $request, array $headers) use ($message) {
                return response()->json([
                    'success' => false,
                    'data' => null,
                    'meta' => (object) [],
                    'error' => [
                        'message' => $message,
                        'details' => null,
                    ],
                ], 429, $headers);
            });
    }

    private function ipKey(Request $request, string $suffix = ''): string
    {
        $ip = $request->ip() ?: 'unknown';
        return $suffix !== '' ? $ip . '|' . $suffix : $ip;
    }

    private function userOrIpKey(Request $request, string $suffix = ''): string
    {
        $base = $request->user()
            ? 'user:' . $request->user()->id
            : 'ip:' . ($request->ip() ?: 'unknown');

        return $suffix !== '' ? $base . '|' . $suffix : $base;
    }

    private function ipEmailKey(Request $request, string $suffix = ''): string
    {
        $email = strtolower(trim((string) $request->input('email', '')));
        $base = ($request->ip() ?: 'unknown') . '|' . ($email !== '' ? $email : 'no-email');

        return $suffix !== '' ? $base . '|' . $suffix : $base;
    }

    private function ipChallengeKey(Request $request, string $suffix = ''): string
    {
        $challengeId = trim((string) $request->input('challenge_id', 'no-challenge'));
        $base = ($request->ip() ?: 'unknown') . '|' . $challengeId;

        return $suffix !== '' ? $base . '|' . $suffix : $base;
    }

    private function auditedModels(): array
    {
        return [
            Category::class,
            SubCategory::class,
            Product::class,
            License::class,
            ProductStock::class,
            ProductStockLog::class,
            Order::class,
            Payment::class,
            Delivery::class,
            Voucher::class,
            DiscountCampaign::class,
            DiscountCampaignTarget::class,
            ReferralSetting::class,
            Banner::class,
            Popup::class,
            Page::class,
            Faq::class,
            Setting::class,
            PaymentGateway::class,
            WalletTopup::class,
            WithdrawRequest::class,
            AdminRole::class,
            AdminPermission::class,
            User::class,
        ];
    }
}
