<?php

namespace App\Http\Controllers\Api\V1\Bootstrap;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Favorite;
use App\Services\SupabaseStorageService;
use App\Services\SystemAccessService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class ShellBootstrapController extends Controller
{
    use ApiResponse;

    public function __invoke(
        Request $request,
        SupabaseStorageService $supabase,
        SystemAccessService $access
    ) {
        $user = $request->user();

        if (!$user) {
            return $this->fail('Unauthenticated', 401);
        }

        $cartId = Cart::query()
            ->where('user_id', $user->id)
            ->value('id');

        $cartCount = $cartId
            ? (int) CartItem::query()->where('cart_id', $cartId)->sum('qty')
            : 0;

        $favoriteCount = (int) Favorite::query()
            ->where('user_id', $user->id)
            ->count();

        return $this->ok([
            'auth' => [
                'is_logged_in' => true,
                'user' => $this->serializeUser($user, $supabase),
            ],
            'nav' => [
                'cart_count' => $cartCount,
                'favorite_count' => $favoriteCount,
            ],
            'features' => [
                'catalog' => [
                    'enabled' => $access->enabled('catalog_access'),
                    'message' => $access->message(
                        'catalog_access',
                        'Katalog sedang maintenance.'
                    ),
                ],
                'checkout' => [
                    'enabled' => $access->enabled('checkout_access'),
                    'message' => $access->message(
                        'checkout_access',
                        'Checkout sedang maintenance.'
                    ),
                ],
                'topup' => [
                    'enabled' => $access->enabled('topup_access'),
                    'message' => $access->message(
                        'topup_access',
                        'Top up sedang maintenance.'
                    ),
                ],
            ],
        ]);
    }

    private function serializeUser($user, SupabaseStorageService $supabase): array
    {
        $bucket = (string) config('services.supabase.bucket_avatars', 'avatars');

        $avatarUrl = null;
        if (!empty($user->avatar_path)) {
            $avatarUrl = $supabase->publicObjectUrl($bucket, $user->avatar_path);
        } elseif (!empty($user->avatar)) {
            $avatarUrl = $user->avatar;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'full_name' => $user->full_name,
            'email' => $user->email,
            'role' => $user->role,
            'tier' => $user->tier ?? 'member',
            'referral_code' => $user->referral_code,
            'avatar' => $user->avatar,
            'avatar_path' => $user->avatar_path,
            'avatar_url' => $avatarUrl,
        ];
    }
}