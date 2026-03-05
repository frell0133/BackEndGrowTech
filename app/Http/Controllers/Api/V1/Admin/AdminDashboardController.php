<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\WalletTopup;
use App\Support\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

class AdminDashboardController extends Controller
{
    use ApiResponse;

    public function summary(Request $request)
    {
        try {
            [$start, $end] = $this->resolveRange($request);

            $status    = $request->query('status');
            $productId = $request->query('product_id');
            $userTier  = $request->query('user_tier');
            $q         = trim((string) $request->query('q', ''));

            $hasUserTier = Schema::hasColumn('users', 'tier');
            $hasFullName = Schema::hasColumn('users', 'full_name');

            // Base query
            $ordersQ = Order::query()->whereBetween('orders.created_at', [$start, $end]);

            // JOIN users hanya jika diperlukan (tier atau keyword user)
            $needJoinUsers = ($userTier && $hasUserTier) || ($q !== '');
            if ($needJoinUsers) {
                $ordersQ->join('users', 'users.id', '=', 'orders.user_id')
                        ->select('orders.*');
            }

            if ($status) {
                $ordersQ->where('orders.status', $status);
            }

            if ($userTier && $hasUserTier) {
                $ordersQ->where('users.tier', $userTier);
            }

            if ($productId) {
                $ordersQ->whereExists(function ($sub) use ($productId) {
                    $sub->select(DB::raw(1))
                        ->from('order_items')
                        ->whereColumn('order_items.order_id', 'orders.id')
                        ->where('order_items.product_id', (int) $productId);
                });
            }

            if ($q !== '') {
                $ordersQ->where(function ($qq) use ($q, $needJoinUsers, $hasFullName) {
                    $qq->where('orders.invoice_number', 'like', "%{$q}%");

                    if ($needJoinUsers) {
                        $qq->orWhere('users.email', 'like', "%{$q}%")
                           ->orWhere('users.name', 'like', "%{$q}%");

                        if ($hasFullName) {
                            $qq->orWhere('users.full_name', 'like', "%{$q}%");
                        }
                    }
                });
            }

            // Counts by status
            $statusCounts = (clone $ordersQ)
                ->select('orders.status', DB::raw('COUNT(*) as total'))
                ->groupBy('orders.status')
                ->pluck('total', 'orders.status')
                ->toArray();

            $totalOrders   = array_sum($statusCounts);
            $paidOrders    = (int) ($statusCounts['paid'] ?? 0);
            $pendingOrders = (int) ($statusCounts['pending'] ?? 0);
            $failedOrders  = (int) ($statusCounts['failed'] ?? 0);
            $processOrders = (int) (($statusCounts['created'] ?? 0) + ($statusCounts['pending'] ?? 0));
            $refundOrders  = (int) ($statusCounts['refunded'] ?? 0);

            // Revenue only paid/fulfilled
            $revenueQ = (clone $ordersQ)->whereIn('orders.status', ['paid', 'fulfilled']);
            $grossRevenue = (int) floor((float) $revenueQ->sum('orders.amount'));

            // Daily chart
            $rawDaily = (clone $revenueQ)
                ->selectRaw("DATE(orders.created_at) as d, SUM(orders.amount) as total")
                ->groupBy('d')
                ->orderBy('d')
                ->get()
                ->keyBy('d');

            $labels = [];
            $series = [];
            $cursor = Carbon::parse($start)->startOfDay();
            $endDay = Carbon::parse($end)->startOfDay();

            while ($cursor->lte($endDay)) {
                $key = $cursor->toDateString();
                $labels[] = $key;
                $series[] = (int) floor((float) ($rawDaily[$key]->total ?? 0));
                $cursor->addDay();
            }

            // Global totals
            $totalProducts   = (int) Product::query()->count();
            $totalUsers      = (int) User::query()->count();
            $totalCategories = (int) Category::query()->count();

            // Topup total (guard if table not exists)
            $topupTotal = 0;
            if (Schema::hasTable('wallet_topups') && Schema::hasColumn('wallet_topups', 'status')) {
                $topupTotal = (int) floor((float) WalletTopup::query()
                    ->whereBetween('created_at', [$start, $end])
                    ->where('status', 'paid')
                    ->sum('amount'));
            }

            $productsForFilter = Product::query()
                ->select('id', 'name')
                ->orderBy('name')
                ->limit(500)
                ->get();

            return $this->ok([
                'range' => [
                    'start' => Carbon::parse($start)->toDateString(),
                    'end'   => Carbon::parse($end)->toDateString(),
                ],
                'totals' => [
                    'products'   => $totalProducts,
                    'categories' => $totalCategories,
                    'users'      => $totalUsers,
                ],
                'transactions' => [
                    'total'    => $totalOrders,
                    'paid'     => $paidOrders,
                    'pending'  => $pendingOrders,
                    'process'  => $processOrders,
                    'failed'   => $failedOrders,
                    'refunded' => $refundOrders,
                ],
                'revenue' => [
                    'gross'       => $grossRevenue,
                    'topup_total' => $topupTotal,
                ],
                'chart' => [
                    'labels'  => $labels,
                    'revenue' => $series,
                ],
                'filter_options' => [
                    'products' => $productsForFilter,
                    'status' => ['created','pending','paid','fulfilled','failed','expired','refunded'],
                    'user_tier' => ['member','reseller','vip'],
                    'range_presets' => ['today','7d','30d'],
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('ADMIN_DASHBOARD_SUMMARY_ERROR', [
                'message' => $e->getMessage(),
                'trace' => substr($e->getTraceAsString(), 0, 2000),
            ]);
            return $this->fail('Internal Server Error', 500, [
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function resolveRange(Request $request): array
    {
        $from = $request->query('from');
        $to   = $request->query('to');

        if ($from && $to) {
            return [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()];
        }

        $range = $request->query('range', '7d');

        if ($range === 'today') return [Carbon::now()->startOfDay(), Carbon::now()->endOfDay()];
        if ($range === '30d')   return [Carbon::now()->subDays(29)->startOfDay(), Carbon::now()->endOfDay()];
        return [Carbon::now()->subDays(6)->startOfDay(), Carbon::now()->endOfDay()];
    }
}