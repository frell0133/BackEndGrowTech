<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\License;
use App\Models\Product;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Voucher;
use App\Models\Setting;
use App\Enums\OrderStatus;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UserCartController extends Controller
{
    use ApiResponse;

    private function requireUser(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return $this->fail('Unauthenticated', 401);
        }
        return $user;
    }

    private function cartForUser(int $userId): Cart
    {
        return Cart::firstOrCreate(['user_id' => $userId]);
    }

    private function getTaxPercent(): int
    {
        $row = Setting::query()
            ->where('group', 'payment')
            ->where('key', 'tax_percent')
            ->first();

        if (!$row) return 0;

        $val = $row->value;

        // kalau ternyata string JSON
        if (is_string($val)) {
            $decoded = json_decode($val, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $val = $decoded;
            }
        }

        if (is_array($val)) {
            return (int) ($val['percent'] ?? 0);
        }

        if (is_numeric($val)) {
            return (int) $val;
        }

        return 0;
    }

    private function resolveUnitPrice(Product $product, string $role): int
    {
        $tier = (array) ($product->tier_pricing ?? []);
        $unitPrice = 0;

        if (!empty($tier)) {
            $unitPrice = (int) ($tier[$role] ?? 0);
            if ($unitPrice <= 0) $unitPrice = (int) ($tier['member'] ?? 0);
            if ($unitPrice <= 0) {
                $vals = array_values($tier);
                $unitPrice = (int) ($vals[0] ?? 0);
            }
        }

        if ($unitPrice <= 0) {
            $unitPrice = (int) ($product->price ?? 0);
        }

        return (int) $unitPrice;
    }

    public function show(Request $request)
    {
        $user = $this->requireUser($request);
        if ($user instanceof \Illuminate\Http\JsonResponse) return $user;

        $cart = $this->cartForUser((int) $user->id);

        $role = (string) ($user->role ?? 'member');
        $taxPercent = $this->getTaxPercent(); // default 0

        $items = CartItem::query()
            ->where('cart_id', $cart->id)
            ->with([
                'product',
                'product.category',
                'product.subcategory',
            ])
            ->get()
            ->map(function (CartItem $item) use ($role) {
                $p = $item->product;

                $stock = License::query()
                    ->where('product_id', $item->product_id)
                    ->where('status', License::STATUS_AVAILABLE)
                    ->count();

                $canBuy = (bool) ($p?->is_active && $p?->is_published && $stock > 0);

                $unitPrice = 0;
                if ($p) {
                    $tier = (array) ($p->tier_pricing ?? []);
                    if (!empty($tier)) {
                        $unitPrice = (int) ($tier[$role] ?? 0);
                        if ($unitPrice <= 0) $unitPrice = (int) ($tier['member'] ?? 0);
                        if ($unitPrice <= 0) {
                            $vals = array_values($tier);
                            $unitPrice = (int) ($vals[0] ?? 0);
                        }
                    }
                    if ($unitPrice <= 0) $unitPrice = (int) ($p->price ?? 0);
                }

                $qty = (int) ($item->qty ?? 1);

                return [
                    'id' => $item->id,
                    'qty' => $qty,
                    'unit_price' => (int) $unitPrice,
                    'line_subtotal' => (int) $unitPrice * $qty,
                    'product' => $p,
                    'stock_available' => $stock,
                    'can_buy' => $canBuy,
                ];
            });

        $subtotal = (float) $items->sum('line_subtotal');

        $discountTotal = 0.0;

        $taxAmount = 0.0;
        if ($taxPercent > 0) {
            $taxAmount = round($subtotal * ($taxPercent / 100), 2);
        }

        $total = (float) max(0, ($subtotal + $taxAmount) - $discountTotal);

        return $this->ok([
            'items' => $items,
            'summary' => [
                'subtotal' => $subtotal,
                'discount_total' => $discountTotal,
                'tax_percent' => $taxPercent,
                'tax_amount' => $taxAmount,
                'total' => $total,
            ],
        ]);
    }

    public function add(Request $request)
    {
        $user = $this->requireUser($request);
        if ($user instanceof \Illuminate\Http\JsonResponse) return $user;

        $cart = $this->cartForUser((int) $user->id);

        $v = $request->validate([
            'product_id' => ['required', 'integer', 'min:1'],
            'qty' => ['nullable', 'integer', 'min:1', 'max:99'],
        ]);

        $qty = (int) ($v['qty'] ?? 1);

        $product = Product::query()
            ->where('id', $v['product_id'])
            ->where('is_active', true)
            ->where('is_published', true)
            ->first();

        if (!$product) return $this->fail('Product not available', 404);

        $stock = License::query()
            ->where('product_id', $product->id)
            ->where('status', License::STATUS_AVAILABLE)
            ->count();

        if ($stock <= 0) {
            return $this->fail('Out of stock', 422, ['stock_available' => 0]);
        }

        $item = CartItem::query()
            ->where('cart_id', $cart->id)
            ->where('product_id', $product->id)
            ->first();

        if ($item) {
            $newQty = min(99, $item->qty + $qty);
            $item->update(['qty' => $newQty]);
        } else {
            $item = CartItem::create([
                'cart_id' => $cart->id,
                'product_id' => $product->id,
                'qty' => $qty,
            ]);
        }

        return $this->ok(['message' => 'Added to cart', 'item' => $item]);
    }

    public function update(Request $request, int $id)
    {
        $user = $this->requireUser($request);
        if ($user instanceof \Illuminate\Http\JsonResponse) return $user;

        $cart = $this->cartForUser((int) $user->id);

        $v = $request->validate([
            'qty' => ['required', 'integer', 'min:1', 'max:99'],
        ]);

        $item = CartItem::query()
            ->where('id', $id)
            ->where('cart_id', $cart->id)
            ->firstOrFail();

        $item->update(['qty' => (int) $v['qty']]);

        return $this->ok(['message' => 'Cart updated', 'item' => $item]);
    }

    public function remove(Request $request, int $id)
    {
        $user = $this->requireUser($request);
        if ($user instanceof \Illuminate\Http\JsonResponse) return $user;

        $cart = $this->cartForUser((int) $user->id);

        $item = CartItem::query()
            ->where('id', $id)
            ->where('cart_id', $cart->id)
            ->firstOrFail();

        $item->delete();

        return $this->ok(['message' => 'Removed']);
    }

    public function checkout(Request $request)
    {
        $user = $this->requireUser($request);
        if ($user instanceof \Illuminate\Http\JsonResponse) return $user;

        $cart = $this->cartForUser((int) $user->id);

        $v = $request->validate([
            'voucher_code' => ['nullable', 'string', 'max:50'],
        ]);

        $role = (string) ($user->role ?? 'member');
        $taxPercent = $this->getTaxPercent();

        $cartItems = CartItem::query()
            ->where('cart_id', $cart->id)
            ->with(['product'])
            ->get();

        if ($cartItems->isEmpty()) return $this->fail('Cart kosong', 422);

        foreach ($cartItems as $ci) {
            $p = $ci->product;
            if (!$p || !$p->is_active || !$p->is_published) {
                return $this->fail('Ada product yang tidak tersedia', 422, [
                    'product_id' => (int) $ci->product_id,
                ]);
            }

            $need = (int) ($ci->qty ?? 1);
            $stock = License::query()
                ->where('product_id', (int) $ci->product_id)
                ->where('status', License::STATUS_AVAILABLE)
                ->count();

            if ($stock < $need) {
                return $this->fail('Stock tidak cukup', 422, [
                    'product_id' => (int) $ci->product_id,
                    'stock_available' => (int) $stock,
                    'qty_requested' => (int) $need,
                ]);
            }

            $unit = $this->resolveUnitPrice($p, $role);
            if ($unit <= 0) {
                return $this->fail('Harga product belum diset (tier_pricing/price kosong)', 422, [
                    'product_id' => (int) $ci->product_id,
                ]);
            }
        }

        $response = DB::transaction(function () use ($cart, $cartItems, $role, $taxPercent, $v, $user) {

            $computedItems = [];
            $subtotal = 0.0;

            foreach ($cartItems as $ci) {
                $p = $ci->product;
                $qty = (int) ($ci->qty ?? 1);
                $unitPrice = $this->resolveUnitPrice($p, $role);

                $line = (float) ($unitPrice * $qty);
                $subtotal += $line;

                $computedItems[] = [
                    'product_id' => (int) $p->id,
                    'qty' => $qty,
                    'unit_price' => (float) $unitPrice,
                    'line_subtotal' => (float) $line,
                    'product_name' => (string) ($p->name ?? null),
                    'product_slug' => (string) ($p->slug ?? null),
                ];
            }

            $discountTotal = 0.0;
            $voucher = null;

            if (!empty($v['voucher_code'])) {
                $code = strtoupper(trim((string) $v['voucher_code']));

                $voucher = Voucher::query()->where('code', $code)->first();
                if (!$voucher) return $this->fail('Voucher tidak ditemukan', 404);
                if (!$voucher->is_active) return $this->fail('Voucher tidak aktif', 422);
                if ($voucher->expires_at && Carbon::parse($voucher->expires_at)->isPast()) {
                    return $this->fail('Voucher sudah kedaluwarsa', 422);
                }
                if ($voucher->min_purchase !== null && $subtotal < (float) $voucher->min_purchase) {
                    return $this->fail('Subtotal belum memenuhi minimal pembelian voucher', 422);
                }
                if ($voucher->quota !== null) {
                    $used = $voucher->orders()->count();
                    if ($used >= (int) $voucher->quota) {
                        return $this->fail('Kuota voucher sudah habis', 422);
                    }
                }

                if ($voucher->type == 'percent') {
                    $discountTotal = (float) floor($subtotal * ((float) $voucher->value / 100));
                } else {
                    $discountTotal = (float) $voucher->value;
                }

                if ($discountTotal > $subtotal) $discountTotal = $subtotal;
            }

            $taxAmount = 0.0;
            if ($taxPercent > 0) {
                $taxAmount = round($subtotal * ($taxPercent / 100), 2);
            }

            $amount = (float) max(0, ($subtotal + $taxAmount) - $discountTotal);

            $invoice = 'INV-' . now()->format('Ymd') . '-' . Str::upper(Str::random(8));

            $order = Order::create([
                'user_id' => (int) $user->id,
                'product_id' => null,
                'invoice_number' => $invoice,
                'status' => OrderStatus::CREATED->value,
                'qty' => null,
                'subtotal' => (float) $subtotal,
                'discount_total' => (float) $discountTotal,
                'tax_percent' => (int) $taxPercent,
                'tax_amount' => (float) $taxAmount,
                'amount' => (float) $amount,
                'payment_gateway_code' => null,
            ]);

            foreach ($computedItems as $it) {
                OrderItem::create([
                    'order_id' => (int) $order->id,
                    'product_id' => (int) $it['product_id'],
                    'qty' => (int) $it['qty'],
                    'unit_price' => (float) $it['unit_price'],
                    'line_subtotal' => (float) $it['line_subtotal'],
                    'product_name' => $it['product_name'],
                    'product_slug' => $it['product_slug'],
                ]);
            }

            if ($voucher) {
                $order->vouchers()->syncWithoutDetaching([
                    $voucher->id => ['discount_amount' => (float) $discountTotal],
                ]);
            }

            CartItem::query()->where('cart_id', (int) $cart->id)->delete();

            return $this->ok([
                'order' => $order->fresh()->load(['items.product','vouchers']),
            ]);
        });

        return $response;
    }

    public function checkoutPreview(Request $request)
    {
        $user = $this->requireUser($request);
        if ($user instanceof \Illuminate\Http\JsonResponse) return $user;

        $cart = $this->cartForUser((int) $user->id);

        $v = $request->validate([
            'voucher_code' => ['nullable', 'string', 'max:50'],
        ]);

        $role = (string) ($user->role ?? 'member');
        $taxPercent = $this->getTaxPercent();

        $cartItems = CartItem::query()
            ->where('cart_id', $cart->id)
            ->with(['product'])
            ->get();

        if ($cartItems->isEmpty()) {
            $lastOrder = \App\Models\Order::query()
                ->where('user_id', $user->id)
                ->with(['items.product'])
                ->latest('id')
                ->first();

            if (!$lastOrder) {
                return $this->fail('Cart kosong', 422);
            }

            return $this->ok([
                'mode' => 'order',
                'order' => $lastOrder,
                'items' => $lastOrder->items,
                'summary' => [
                    'subtotal' => (float) $lastOrder->subtotal,
                    'discount_total' => (float) $lastOrder->discount_total,
                    'tax_percent' => (int) ($lastOrder->tax_percent ?? 0),
                    'tax_amount' => (float) $lastOrder->tax_amount,
                    'total' => (float) $lastOrder->amount,
                ],
            ]);
        }

        foreach ($cartItems as $ci) {
            $p = $ci->product;
            if (!$p || !$p->is_active || !$p->is_published) {
                return $this->fail('Ada product yang tidak tersedia', 422, [
                    'product_id' => (int) $ci->product_id,
                ]);
            }

            $need = (int) ($ci->qty ?? 1);
            $stock = License::query()
                ->where('product_id', (int) $ci->product_id)
                ->where('status', License::STATUS_AVAILABLE)
                ->count();

            if ($stock < $need) {
                return $this->fail('Stock tidak cukup', 422, [
                    'product_id' => (int) $ci->product_id,
                    'stock_available' => (int) $stock,
                    'qty_requested' => (int) $need,
                ]);
            }

            $unit = $this->resolveUnitPrice($p, $role);
            if ($unit <= 0) {
                return $this->fail('Harga product belum diset (tier_pricing/price kosong)', 422, [
                    'product_id' => (int) $ci->product_id,
                ]);
            }
        }

        $items = [];
        $subtotal = 0.0;

        foreach ($cartItems as $ci) {
            $p = $ci->product;
            $qty = (int) ($ci->qty ?? 1);
            $unitPrice = $this->resolveUnitPrice($p, $role);

            $line = (float) ($unitPrice * $qty);
            $subtotal += $line;

            $items[] = [
                'product_id' => (int) $p->id,
                'qty' => $qty,
                'unit_price' => (float) $unitPrice,
                'line_subtotal' => (float) $line,
                'product' => $p,
            ];
        }

        $discountTotal = 0.0;

        if (!empty($v['voucher_code'])) {
            $code = strtoupper(trim((string) $v['voucher_code']));

            $voucher = Voucher::query()->where('code', $code)->first();
            if (!$voucher) return $this->fail('Voucher tidak ditemukan', 404);
            if (!$voucher->is_active) return $this->fail('Voucher tidak aktif', 422);
            if ($voucher->expires_at && Carbon::parse($voucher->expires_at)->isPast()) {
                return $this->fail('Voucher sudah kedaluwarsa', 422);
            }
            if ($voucher->min_purchase !== null && $subtotal < (float) $voucher->min_purchase) {
                return $this->fail('Subtotal belum memenuhi minimal pembelian voucher', 422);
            }
            if ($voucher->quota !== null) {
                $used = $voucher->orders()->count();
                if ($used >= (int) $voucher->quota) {
                    return $this->fail('Kuota voucher sudah habis', 422);
                }
            }

            if ($voucher->type === 'percent') {
                $discountTotal = (float) floor($subtotal * ((float) $voucher->value / 100));
            } else {
                $discountTotal = (float) $voucher->value;
            }

            if ($discountTotal > $subtotal) $discountTotal = $subtotal;
        }

        $taxAmount = 0.0;
        if ($taxPercent > 0) {
            $taxAmount = round($subtotal * ($taxPercent / 100), 2);
        }

        $total = (float) max(0, ($subtotal + $taxAmount) - $discountTotal);

        return $this->ok([
            'mode' => 'cart',
            'items' => $items,
            'summary' => [
                'subtotal' => $subtotal,
                'discount_total' => $discountTotal,
                'tax_percent' => $taxPercent,
                'tax_amount' => $taxAmount,
                'total' => $total,
            ],
        ]);
    }
}