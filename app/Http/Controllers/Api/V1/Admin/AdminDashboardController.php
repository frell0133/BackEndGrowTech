<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\SubCategory;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\PublicCache;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminDashboardController extends Controller
{
    use ApiResponse;

    public function summary(Request $request)
    {
        $queryHash = md5(json_encode($request->query(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $data = PublicCache::rememberDashboard('summary:' . $queryHash, 60, function () use ($request) {
            [$start, $end] = $this->resolveRange($request);

            $status = $request->query('status');
            $productId = $request->query('product_id');
            $userTier = $request->query('user_tier');
            $q = trim((string) $request->query('q', ''));

            $hasTier = Schema::hasColumn('users', 'tier');
            $hasFullName = Schema::hasColumn('users', 'full_name');

            $allStatusCounts = Order::query()
                ->select('status', DB::raw('COUNT(*) as total'))
                ->groupBy('status')
                ->pluck('total', 'status')
                ->toArray();

            $allTotal = array_sum($allStatusCounts);
            $allPaid = (int) ($allStatusCounts['paid'] ?? 0) + (int) ($allStatusCounts['fulfilled'] ?? 0);
            $allPending = (int) ($allStatusCounts['pending'] ?? 0) + (int) ($allStatusCounts['created'] ?? 0);
            $allFailed = (int) ($allStatusCounts['failed'] ?? 0) + (int) ($allStatusCounts['expired'] ?? 0);

            $revenueStatuses = ['paid', 'fulfilled'];

            $revenueToday = (int) floor((float) Order::query()
                ->whereIn('status', $revenueStatuses)
                ->whereBetween('created_at', [Carbon::now()->startOfDay(), Carbon::now()->endOfDay()])
                ->sum('amount'));

            $revenueMonth = (int) floor((float) Order::query()
                ->whereIn('status', $revenueStatuses)
                ->whereBetween('created_at', [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()])
                ->sum('amount'));

            $revenueTotal = (int) floor((float) Order::query()
                ->whereIn('status', $revenueStatuses)
                ->sum('amount'));

            $commissionSub = DB::table('referral_transactions')
                ->selectRaw('order_id, COALESCE(SUM(commission_amount), 0) as total_commission')
                ->whereNotNull('order_id')
                ->where('status', 'valid')
                ->groupBy('order_id');

            $profitExpression = 'GREATEST(orders.amount - COALESCE(orders.tax_amount, 0) - COALESCE(rc.total_commission, 0), 0)';

            $profitToday = (int) floor((float) Order::query()
                ->leftJoinSub($commissionSub, 'rc', function ($join) {
                    $join->on('rc.order_id', '=', 'orders.id');
                })
                ->whereIn('orders.status', $revenueStatuses)
                ->whereBetween('orders.created_at', [Carbon::now()->startOfDay(), Carbon::now()->endOfDay()])
                ->sum(DB::raw($profitExpression)));

            $profitMonth = (int) floor((float) Order::query()
                ->leftJoinSub($commissionSub, 'rc', function ($join) {
                    $join->on('rc.order_id', '=', 'orders.id');
                })
                ->whereIn('orders.status', $revenueStatuses)
                ->whereBetween('orders.created_at', [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()])
                ->sum(DB::raw($profitExpression)));

            $profitTotal = (int) floor((float) Order::query()
                ->leftJoinSub($commissionSub, 'rc', function ($join) {
                    $join->on('rc.order_id', '=', 'orders.id');
                })
                ->whereIn('orders.status', $revenueStatuses)
                ->sum(DB::raw($profitExpression)));

            $totalCategories = (int) Category::query()->count();
            $totalSubCategories = class_exists(SubCategory::class)
                ? (int) SubCategory::query()->count()
                : 0;
            $totalProducts = (int) Product::query()->count();
            $totalUsers = (int) User::query()->count();

            $usersByTier = [
                'member' => 0,
                'reseller' => 0,
                'vip' => 0,
            ];

            if ($hasTier) {
                $tierCounts = User::query()
                    ->select('tier', DB::raw('COUNT(*) as total'))
                    ->groupBy('tier')
                    ->pluck('total', 'tier')
                    ->toArray();

                $usersByTier = [
                    'member' => (int) ($tierCounts['member'] ?? 0),
                    'reseller' => (int) ($tierCounts['reseller'] ?? 0),
                    'vip' => (int) ($tierCounts['vip'] ?? 0),
                ];
            }

            $ordersQ = Order::query()
                ->from('orders')
                ->whereBetween('orders.created_at', [$start, $end]);

            $needJoinUsers = ($userTier && $hasTier) || ($q !== '');
            if ($needJoinUsers) {
                $ordersQ->join('users', 'users.id', '=', 'orders.user_id')
                    ->select('orders.*');
            }

            if ($status) {
                $ordersQ->where('orders.status', $status);
            }

            if ($userTier && $hasTier) {
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

            $revenueDaily = (clone $ordersQ)
                ->whereIn('orders.status', $revenueStatuses)
                ->selectRaw('DATE(orders.created_at) as d, SUM(orders.amount) as total')
                ->groupBy('d')
                ->orderBy('d')
                ->get()
                ->keyBy('d');

            $profitDaily = (clone $ordersQ)
                ->leftJoinSub($commissionSub, 'rc', function ($join) {
                    $join->on('rc.order_id', '=', 'orders.id');
                })
                ->whereIn('orders.status', $revenueStatuses)
                ->selectRaw('DATE(orders.created_at) as d, SUM(' . $profitExpression . ') as total')
                ->groupBy('d')
                ->orderBy('d')
                ->get()
                ->keyBy('d');

            $labels = [];
            $revenueSeries = [];
            $profitSeries = [];
            $cursor = Carbon::parse($start)->startOfDay();
            $endDay = Carbon::parse($end)->startOfDay();

            while ($cursor->lte($endDay)) {
                $key = $cursor->toDateString();
                $labels[] = $key;
                $revenueSeries[] = (int) floor((float) ($revenueDaily[$key]->total ?? 0));
                $profitSeries[] = (int) floor((float) ($profitDaily[$key]->total ?? 0));
                $cursor->addDay();
            }

            return [
                'range' => [
                    'start' => Carbon::parse($start)->toDateString(),
                    'end' => Carbon::parse($end)->toDateString(),
                ],
                'transactions_all_time' => [
                    'total' => $allTotal,
                    'success' => $allPaid,
                    'pending' => $allPending,
                    'failed' => $allFailed,
                    'by_status' => $allStatusCounts,
                ],
                'revenue' => [
                    'today' => $revenueToday,
                    'month' => $revenueMonth,
                    'total' => $revenueTotal,
                ],
                'profit' => [
                    'today' => $profitToday,
                    'month' => $profitMonth,
                    'total' => $profitTotal,
                    'formula' => 'amount - tax_amount - referral_commission_valid',
                    'note' => 'Profit estimasi dihitung dari amount order setelah diskon yang berhasil dibayar, lalu dikurangi tax_amount dan komisi referral valid. Project saat ini belum memiliki field modal/HPP produk.',
                ],
                'products' => [
                    'categories' => $totalCategories,
                    'subcategories' => $totalSubCategories,
                    'products' => $totalProducts,
                ],
                'users' => [
                    'total' => $totalUsers,
                    'by_tier' => $usersByTier,
                    'total_transaction_nominal' => $revenueTotal,
                ],
                'chart' => [
                    'labels' => $labels,
                    'revenue' => $revenueSeries,
                    'profit' => $profitSeries,
                ],
                'filter_options' => [
                    'products' => Product::query()->select('id', 'name')->orderBy('name')->limit(300)->get(),
                    'status' => ['created', 'pending', 'paid', 'fulfilled', 'failed', 'expired', 'refunded'],
                    'user_tier' => ['member', 'reseller', 'vip'],
                    'range_presets' => ['today', '7d', '30d'],
                ],
            ];
        });

        return $this->ok($data);
    }

    private function resolveRange(Request $request): array
    {
        $from = $request->query('from');
        $to = $request->query('to');

        if ($from && $to) {
            return [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()];
        }

        $range = $request->query('range', '7d');
        if ($range === 'today') return [Carbon::now()->startOfDay(), Carbon::now()->endOfDay()];
        if ($range === '30d') return [Carbon::now()->subDays(29)->startOfDay(), Carbon::now()->endOfDay()];
        return [Carbon::now()->subDays(6)->startOfDay(), Carbon::now()->endOfDay()];
    }
}
