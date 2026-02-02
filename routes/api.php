<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\SocialAuthController;

use App\Http\Controllers\Api\V1\User\ProductController;
use App\Http\Controllers\Api\V1\User\UserOrderController;
use App\Http\Controllers\Api\V1\User\UserDeliveryController;
use App\Http\Controllers\Api\V1\User\UserWalletController;
use App\Http\Controllers\Api\V1\User\UserReferralController;
use App\Http\Controllers\Api\V1\User\UserWithdrawController;
use App\Http\Controllers\Api\V1\User\UserVoucherController;
use App\Http\Controllers\Api\V1\User\UserProfileController;

use App\Http\Controllers\Api\V1\Admin\AdminUserController;
use App\Http\Controllers\Api\V1\Admin\AdminProductController;
use App\Http\Controllers\Api\V1\Admin\AdminLicenseController;
use App\Http\Controllers\Api\V1\Admin\AdminOrderController;
use App\Http\Controllers\Api\V1\Admin\AdminPaymentController;
use App\Http\Controllers\Api\V1\Admin\AdminDeliveryController;
use App\Http\Controllers\Api\V1\Admin\AdminWalletController;
use App\Http\Controllers\Api\V1\Admin\AdminReferralController;
use App\Http\Controllers\Api\V1\Admin\AdminWithdrawController;
use App\Http\Controllers\Api\V1\Admin\AdminVoucherController;
use App\Http\Controllers\Api\V1\Admin\AdminAuditLogController;
use App\Http\Controllers\Api\V1\Admin\AdminPopupController;
use App\Http\Controllers\Api\V1\Admin\AdminPageController;
use App\Http\Controllers\Api\V1\Admin\AdminFaqController;
use App\Http\Controllers\Api\V1\Admin\AdminBannerController;
use App\Http\Controllers\Api\V1\Admin\AdminSiteSettingController;
use App\Http\Controllers\Api\V1\Admin\PaymentGatewayController;

use App\Http\Controllers\Api\V1\Content\ContentController;

use App\Http\Controllers\Api\SupabaseUploadController;

// TOPUP QRIS (Midtrans / Simulate)
use App\Http\Controllers\Api\V1\User\UserTopupController;
use App\Http\Controllers\Api\V1\Webhook\MidtransWebhookController;
use App\Http\Controllers\Api\V1\Simulate\SimulateTopupController;

Route::prefix('v1')->group(function () {

    // =========================
    // 0) HEALTH / VERSION
    // =========================
    Route::get('health', fn () => response()->json([
        'success' => true,
        'data' => ['status' => 'ok'],
        'meta' => (object)[],
        'error' => null,
    ]));

    Route::get('version', fn () => response()->json([
        'success' => true,
        'data' => ['version' => 'dev'],
        'meta' => (object)[],
        'error' => null,
    ]));

    // =========================
    // 1) AUTH & SESSION
    // =========================
    Route::prefix('auth')->group(function () {

        // email/password
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);

        // social login (batasi provider biar aman)
        Route::get('{provider}/redirect', [SocialAuthController::class, 'redirect'])
            ->whereIn('provider', ['google', 'discord']);

        Route::get('{provider}/callback', [SocialAuthController::class, 'callback'])
            ->whereIn('provider', ['google', 'discord']);

        // authenticated
        Route::middleware('auth:sanctum')->group(function () {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::get('me', [AuthController::class, 'me']);

            // profile
            Route::get('me/profile', [UserProfileController::class, 'showProfile']);
            Route::patch('me/profile', [UserProfileController::class, 'updateProfile']);

            // avatar (supabase)
            Route::post('me/avatar/sign', [UserProfileController::class, 'signAvatarUpload']);
            Route::patch('me/avatar', [UserProfileController::class, 'updateAvatar']);
            Route::delete('me/avatar', [UserProfileController::class, 'deleteAvatar']); // optional

            // password
            Route::patch('me/password', [UserProfileController::class, 'updatePassword']);
        });

        // optional stubs
        Route::post('verify-email/send', fn () => response()->json([
            'success' => true, 'data' => ['todo' => true], 'meta' => (object)[], 'error' => null
        ]));

        Route::post('verify-email/confirm', fn () => response()->json([
            'success' => true, 'data' => ['todo' => true], 'meta' => (object)[], 'error' => null
        ]));

        Route::post('password/forgot', fn () => response()->json([
            'success' => true, 'data' => ['todo' => true], 'meta' => (object)[], 'error' => null
        ]));

        Route::post('password/reset', fn () => response()->json([
            'success' => true, 'data' => ['todo' => true], 'meta' => (object)[], 'error' => null
        ]));
    });

    // =========================
    // 2) PRODUCTS (PUBLIC)
    // =========================
    Route::get('products', [ProductController::class, 'index']);
    Route::get('products/{product}', [ProductController::class, 'show']);
    Route::get('products/{product}/availability', fn () => response()->json([
        'success' => true,
        'data' => ['available' => null, 'todo' => true],
        'meta' => (object)[],
        'error' => null,
    ]));

    // =========================
    // 3) USER AREA (AUTH)
    // =========================
    Route::middleware('auth:sanctum')->group(function () {

        // Orders (User)
        Route::post('orders', [UserOrderController::class, 'store']);
        Route::get('orders', [UserOrderController::class, 'index']);
        Route::get('orders/{id}', [UserOrderController::class, 'show']);
        Route::post('orders/{id}/cancel', [UserOrderController::class, 'cancel']);

        // Payments (User)
        Route::post('orders/{id}/payments', [UserOrderController::class, 'createPayment']);
        Route::get('orders/{id}/payments', [UserOrderController::class, 'paymentStatus']);

        // Delivery (User)
        Route::get('orders/{id}/delivery', [UserDeliveryController::class, 'info']);
        Route::post('orders/{id}/delivery/reveal', [UserDeliveryController::class, 'reveal']);
        Route::post('orders/{id}/delivery/resend', [UserDeliveryController::class, 'resend']);

        // Wallet (User)
        Route::get('wallet/summary', [UserWalletController::class, 'summary']);
        Route::get('wallet/ledger', [UserWalletController::class, 'ledger']);

        // 🔥 TOPUP QRIS (INIT: Snap / Simulate)
        Route::post('wallet/topups/init', [UserTopupController::class, 'init'])
            ->middleware('throttle:20,1');


        // Referral (User)
        Route::get('referral', [UserReferralController::class, 'dashboard']);
        Route::post('referral/attach', [UserReferralController::class, 'attach']);

        // Withdraw (User)
        Route::post('withdraws', [UserWithdrawController::class, 'store']);
        Route::get('withdraws', [UserWithdrawController::class, 'index']);
        Route::get('withdraws/{id}', [UserWithdrawController::class, 'show']);

        // Voucher validate (User)
        Route::post('vouchers/validate', [UserVoucherController::class, 'validateCode']);

        // upload sign (user authenticated)
        Route::post('upload/sign', [SupabaseUploadController::class, 'sign']);
    });

    // =========================
    // 4) WEBHOOKS (PUBLIC)  ✅ FIXED ORDER
    // =========================

    // ✅ 4.1 MIDTRANS WEBHOOK (REAL) — HARUS DI ATAS stub {gateway_code}
    Route::post('webhooks/payments/midtrans', [MidtransWebhookController::class, 'handle']);

    // ✅ 4.2 Stub gateway lain (jangan sampai nangkep "midtrans")
    Route::post('webhooks/payments/{gateway_code}', fn (string $gateway_code) => response()->json([
        'success' => true,
        'data' => ['received' => true, 'todo' => true, 'gateway' => $gateway_code],
        'meta' => (object)[],
        'error' => null,
    ]))->where('gateway_code', '^(?!midtrans$).+');

    // ✅ 4.3 SIMULATE: mark topup as PAID (DEV ONLY)
    // Controller akan block di production + saat MIDTRANS_SIMULATE=false
    Route::post('topups/{orderId}/simulate-pay', [SimulateTopupController::class, 'pay']);

    // =========================
    // 5) ADMIN AREA (AUTH + ROLE)
    // =========================
    Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->group(function () {

        // Users
        Route::get('users', [AdminUserController::class, 'index']);
        Route::get('users/{id}', [AdminUserController::class, 'show']);
        Route::patch('users/{id}', [AdminUserController::class, 'update']);
        Route::get('users/{id}/ledger', [AdminUserController::class, 'ledger']);
        Route::get('users/{id}/orders', [AdminUserController::class, 'orders']);
        Route::get('users/{id}/referral', [AdminUserController::class, 'referral']);
        Route::post('users', [AdminUserController::class, 'store']);
        Route::delete('users/{id}', [AdminUserController::class, 'destroy']);
        Route::post('users/{id}/restore', [AdminUserController::class, 'restore']);

        // Products (Admin CRUD)
        Route::post('products', [AdminProductController::class, 'store']);
        Route::patch('products/{id}', [AdminProductController::class, 'update']);
        Route::delete('products/{id}', [AdminProductController::class, 'destroy']);
        Route::post('products/{id}/publish', [AdminProductController::class, 'publish']);

        // Licenses / Inventory
        Route::get('products/{id}/licenses', [AdminLicenseController::class, 'index']);
        Route::get('products/{id}/licenses/summary', [AdminLicenseController::class, 'summary']);
        Route::post('products/{id}/licenses', [AdminLicenseController::class, 'store']);
        Route::post('products/{id}/licenses/upload', [AdminLicenseController::class, 'upload']);
        Route::post('licenses/check-duplicates', [AdminLicenseController::class, 'checkDuplicates']);
        Route::post('products/{id}/take-stock', [AdminLicenseController::class, 'takeStock']);
        Route::get('stock/proofs', [AdminLicenseController::class, 'proofList']);
        Route::get('stock/proofs/{proof_id}', [AdminLicenseController::class, 'proofDownload']);

        // Orders (Admin)
        Route::get('orders', [AdminOrderController::class, 'index']);
        Route::get('orders/{id}', [AdminOrderController::class, 'show']);
        Route::post('orders/{id}/mark-failed', [AdminOrderController::class, 'markFailed']);
        Route::post('orders/{id}/refund', [AdminOrderController::class, 'refund']);

        // Delivery (Admin)
        Route::post('orders/{id}/delivery/resend', [AdminDeliveryController::class, 'resend']);
        Route::post('orders/{id}/delivery/revoke', [AdminDeliveryController::class, 'revoke']);

        // Payment gateways
        Route::get('payment-gateways', [PaymentGatewayController::class, 'index']);
        Route::post('payment-gateways', [PaymentGatewayController::class, 'store']);
        Route::get('payment-gateways/{code}', [PaymentGatewayController::class, 'show']);
        Route::patch('payment-gateways/{code}', [PaymentGatewayController::class, 'update']);
        Route::delete('payment-gateways/{code}', [PaymentGatewayController::class, 'destroy']);

        // Payments (Admin)
        Route::get('payments', [AdminPaymentController::class, 'index']);

        // Wallet ops (Admin)
        Route::get('wallet/ledger', [AdminWalletController::class, 'ledger']);
        Route::post('wallet/adjust', [AdminWalletController::class, 'adjust']);
        Route::post('wallet/topup', [AdminWalletController::class, 'topup']);

        // Referrals (Admin)
        Route::get('referrals', [AdminReferralController::class, 'index']);
        Route::post('referrals/{user_id}/force-unlock', [AdminReferralController::class, 'forceUnlock']);

        // Withdraws (Admin)
        Route::get('withdraws', [AdminWithdrawController::class, 'index']);
        Route::post('withdraws/{id}/approve', [AdminWithdrawController::class, 'approve']);
        Route::post('withdraws/{id}/reject', [AdminWithdrawController::class, 'reject']);
        Route::post('withdraws/{id}/mark-paid', [AdminWithdrawController::class, 'markPaid']);

        // Vouchers (Admin)
        Route::get('vouchers', [AdminVoucherController::class, 'index']);
        Route::post('vouchers', [AdminVoucherController::class, 'store']);
        Route::get('vouchers/{id}', [AdminVoucherController::class, 'show']);
        Route::patch('vouchers/{id}', [AdminVoucherController::class, 'update']);
        Route::delete('vouchers/{id}', [AdminVoucherController::class, 'destroy']);
        Route::get('vouchers/{id}/usage', [AdminVoucherController::class, 'usage']);

        // Audit logs (Admin)
        Route::get('audit-logs', [AdminAuditLogController::class, 'index']);
        Route::get('audit-logs/{id}', [AdminAuditLogController::class, 'show']);

        // Settings
        Route::get('settings', [AdminSiteSettingController::class, 'index']);
        Route::post('settings/upsert', [AdminSiteSettingController::class, 'upsert']);
        Route::delete('settings', [AdminSiteSettingController::class, 'destroy']);
        Route::post('settings/icon/sign', [AdminSiteSettingController::class, 'signIconUpload']);

        // Banners
        Route::get('banners', [AdminBannerController::class, 'index']);
        Route::post('banners', [AdminBannerController::class, 'store']);
        Route::patch('banners/{banner}', [AdminBannerController::class, 'update']);
        Route::post('banners/image/sign', [AdminBannerController::class, 'signImageUpload']);
        Route::patch('banners/{banner}/image', [AdminBannerController::class, 'updateImage']);
        Route::delete('banners/{banner}', [AdminBannerController::class, 'destroy']);

        // Popups
        Route::get('popups', [AdminPopupController::class, 'index']);
        Route::post('popups', [AdminPopupController::class, 'store']);
        Route::get('popups/{popup}', [AdminPopupController::class, 'show'])->whereNumber('popup');
        Route::patch('popups/{popup}', [AdminPopupController::class, 'update'])->whereNumber('popup');
        Route::delete('popups/{popup}', [AdminPopupController::class, 'destroy'])->whereNumber('popup');

        // Admin uploads sign (optional)
        Route::post('uploads/sign', [SupabaseUploadController::class, 'sign']);

        // Pages
        Route::get('pages', [AdminPageController::class, 'index']);
        Route::get('pages/slug/{slug}', [AdminPageController::class, 'showBySlug']);
        Route::put('pages/slug/{slug}', [AdminPageController::class, 'upsertBySlug']);
        Route::patch('pages/{id}', [AdminPageController::class, 'patch']);
        Route::delete('pages/{id}', [AdminPageController::class, 'destroy']);

        // FAQs
        Route::get('faqs', [AdminFaqController::class, 'index']);
        Route::post('faqs', [AdminFaqController::class, 'store']);
        Route::patch('faqs/{id}', [AdminFaqController::class, 'update']);
        Route::delete('faqs/{id}', [AdminFaqController::class, 'destroy']);
    });

    // =========================
    // 6) CONTENT (PUBLIC)
    // =========================
    Route::prefix('content')->group(function () {
        Route::get('settings', [ContentController::class, 'settings']);
        Route::get('banners', [ContentController::class, 'banners']);
        Route::get('popup', [ContentController::class, 'popup']);
        Route::get('pages/{slug}', [ContentController::class, 'page']);
        Route::get('faqs', [ContentController::class, 'faqs']);
    });

    // =========================
    // 7) DEBUG
    // =========================
    Route::get('_debug/db', function () {
        return response()->json([
            'db_connection' => config('database.default'),
            'db_database' => config('database.connections.' . config('database.default') . '.database'),
            'db_host' => config('database.connections.' . config('database.default') . '.host'),
            'app_env' => config('app.env'),
        ]);
    });
});
