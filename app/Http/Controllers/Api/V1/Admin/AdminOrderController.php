<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminOrderController extends Controller
{
    use ApiResponse;

    private function syncExpiredCreatedOrders(): void
    {
        $cutoff = now()->subHour();

        Order::query()
            ->where('status', OrderStatus::CREATED->value)
            ->where('created_at', '<=', $cutoff)
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($rows) {
                foreach ($rows as $row) {
                    DB::transaction(function () use ($row) {
                        $lockedOrder = Order::query()
                            ->with('payment')
                            ->lockForUpdate()
                            ->find($row->id);

                        if (! $lockedOrder) {
                            return;
                        }

                        $currentOrderStatus = (string) ($lockedOrder->status?->value ?? $lockedOrder->status);

                        if ($currentOrderStatus !== OrderStatus::CREATED->value) {
                            return;
                        }

                        $lockedOrder->status = OrderStatus::CANCELLED->value;
                        $lockedOrder->save();

                        $payment = $lockedOrder->payment;

                        if (! $payment) {
                            return;
                        }

                        $currentPaymentStatus = (string) ($payment->status?->value ?? $payment->status);

                        if (in_array($currentPaymentStatus, [
                            PaymentStatus::INITIATED->value,
                            PaymentStatus::PENDING->value,
                        ], true)) {
                            $payment->status = PaymentStatus::EXPIRED->value;
                            $payment->save();
                        }
                    });
                }
            });
    }

    public function index(Request $request)
    {
        $this->syncExpiredCreatedOrders();

        $perPage = max(1, min((int) $request->query('per_page', 20), 100));

        $status = trim((string) $request->query('status', ''));
        $userId = $request->query('user_id');
        $invoice = trim((string) ($request->query('invoice_number') ?: $request->query('invoice') ?: ''));
        $paymentReference = trim((string) $request->query('payment_reference', ''));
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');
        $product = trim((string) $request->query('product', ''));
        $category = trim((string) $request->query('category', ''));

        $query = Order::query()
            ->with([
                'user:id,name,full_name,email',
                'payment:id,order_id,gateway_code,external_id,amount,status,created_at',
                'items' => function ($q) {
                    $q->select([
                        'id',
                        'order_id',
                        'product_id',
                        'qty',
                        'unit_price',
                        'line_subtotal',
                        'product_name',
                        'product_slug',
                    ])->with([
                        'product:id,category_id,subcategory_id,name,slug',
                        'product.category:id,name,slug',
                        'product.subcategory:id,category_id,name,slug',
                    ]);
                },
                'deliveries:id,order_id,license_id,delivery_mode,emailed_at,revealed_at,created_at',
                'deliveries.license:id,product_id,license_key,data_other,note,status,delivered_at,sold_at,updated_at',
            ])
            ->orderByDesc('id');

        if ($status !== '') {
            $query->where('status', $status);
        }

        if ($userId) {
            $query->where('user_id', (int) $userId);
        }

        if ($invoice !== '') {
            $query->where('invoice_number', 'like', "%{$invoice}%");
        }

        if ($paymentReference !== '') {
            $query->whereHas('payment', function ($q) use ($paymentReference) {
                $q->where('external_id', 'like', "%{$paymentReference}%");
            });
        }

        if ($dateFrom) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        if ($product !== '') {
            $query->where(function ($q) use ($product) {
                $q->whereHas('items', function ($item) use ($product) {
                    $item->where('product_name', 'like', "%{$product}%")
                        ->orWhereHas('product', function ($prod) use ($product) {
                            $prod->where('name', 'like', "%{$product}%")
                                ->orWhere('slug', 'like', "%{$product}%");
                        });
                })->orWhereHas('product', function ($prod) use ($product) {
                    $prod->where('name', 'like', "%{$product}%")
                        ->orWhere('slug', 'like', "%{$product}%");
                });
            });
        }

        if ($category !== '') {
            $query->where(function ($q) use ($category) {
                $q->whereHas('items.product.category', function ($cat) use ($category) {
                    $cat->where('name', 'like', "%{$category}%")
                        ->orWhere('slug', 'like', "%{$category}%");
                })->orWhereHas('product.category', function ($cat) use ($category) {
                    $cat->where('name', 'like', "%{$category}%")
                        ->orWhere('slug', 'like', "%{$category}%");
                });
            });
        }

        $paginator = $query->paginate($perPage);

        $paginator->getCollection()->transform(function (Order $order) {
            $items = collect($order->items ?? []);
            $deliveries = collect($order->deliveries ?? []);

            $itemDetails = $items->map(function ($item) {
                $product = $item->product;
                $category = $product?->category;
                $subcategory = $product?->subcategory;

                return [
                    'order_item_id' => (int) $item->id,
                    'product_id' => $product?->id ? (int) $product->id : null,
                    'product' => $item->product_name ?: $product?->name,
                    'product_slug' => $item->product_slug ?: $product?->slug,
                    'category' => $category?->name,
                    'subcategory' => $subcategory?->name,
                    'qty' => (int) ($item->qty ?? 0),
                    'unit_price' => (float) ($item->unit_price ?? 0),
                    'line_subtotal' => (float) ($item->line_subtotal ?? 0),
                ];
            })->values();

            $licenseDetails = $deliveries->map(function ($delivery) {
                $license = $delivery->license;

                return [
                    'delivery_id' => (int) $delivery->id,
                    'delivery_mode' => $delivery->delivery_mode,
                    'emailed_at' => $delivery->emailed_at,
                    'revealed_at' => $delivery->revealed_at,
                    'license_id' => $license?->id ? (int) $license->id : null,
                    'license_key' => $license?->license_key,
                    'data_other' => $license?->data_other,
                    'note' => $license?->note,
                    'status' => $license?->status,
                    'delivered_at' => $license?->delivered_at,
                    'sold_at' => $license?->sold_at,
                ];
            })->values();

            $order->setAttribute('payment_reference', $order->payment?->external_id);
            $order->setAttribute('transaction_datetime', $order->created_at?->timezone('Asia/Jakarta')->format(\DateTimeInterface::ATOM));
            $order->setAttribute('payment_datetime', $order->payment?->created_at?->timezone('Asia/Jakarta')->format(\DateTimeInterface::ATOM));
            $order->setAttribute('total_item_qty', (int) $items->sum(fn ($row) => (int) ($row->qty ?? 0)));
            $order->setAttribute('item_details', $itemDetails);
            $order->setAttribute('license_details', $licenseDetails);

            return $order;
        });

        return $this->ok($paginator);
    }

    public function show(string $id)
    {
        $order = Order::query()
            ->with([
                'user:id,name,full_name,email',
                'payment',
                'items.product.category',
                'items.product.subcategory',
                'deliveries.license',
            ])
            ->findOrFail($id);

        return $this->ok($order);
    }
}
