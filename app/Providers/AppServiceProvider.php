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
use Illuminate\Support\Facades\Event;
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
