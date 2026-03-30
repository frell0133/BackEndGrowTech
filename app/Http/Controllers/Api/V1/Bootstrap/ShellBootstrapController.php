<?php

namespace App\Http\Controllers\Api\V1\Bootstrap;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\Favorite;
use App\Services\SupabaseStorageService;
use App\Services\SystemAccessService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

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

        $cacheKey = sprintf('bootstrap:shell:user:%s', (string) $user->id);

        $payload = Cache::remember($cacheKey, now()->addSeconds(10), function () use ($user, $supabase, $access) {
            $cartCount = (int) CartItem::query()
                ->whereHas('cart', function ($query) use ($user) {
                    $query->where('user_id', $user->id);
                })
                ->sum('qty');

            $favoriteCount = (int) Favorite::query()
                ->where('user_id', $user->id)
                ->count();

            $features = $access->featurePayload([
                'catalog_access',
                'checkout_access',
                'topup_access',
            ]);

            return [
                'auth' => [
                    'is_logged_in' => true,
                    'user' => $this->serializeUser($user, $supabase),
                ],
                'nav' => [
                    'cart_count' => $cartCount,
                    'favorite_count' => $favoriteCount,
                ],
                'features' => [
                    'catalog' => $features['catalog_access'],
                    'checkout' => $features['checkout_access'],
                    'topup' => $features['topup_access'],
                ],
            ];
        });

        return $this->ok($payload);
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
