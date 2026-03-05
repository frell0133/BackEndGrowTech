<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\SubCategory;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminDashboardController extends Controller
{
    use ApiResponse;

    public function summary(Request $request)
    {
        // ====== 1) RANGE untuk chart/filter UI (tetap) ======
        [$start, $end] = $this->resolveRange($request);

        // Filter opsional untuk chart (sesuai mockup modal filter)
        $status    = $request->query('status');      // filter chart/orders range
        $productId = $request->query('product_id');  // via order_items
        $userTier  = $request->query('user_tier');   // users.tier
        $q         = trim((string) $request->query('q', ''));

        $hasTier = Schema::hasColumn('users', 'tier');
        $hasFullName = Schema::hasColumn('users', 'full_name');

        // ====== 2) DATA TRANSAKSI ALL-TIME (yang kamu minta) ======
        $allStatusCounts = Order::query()
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        $allTotal   = array_sum($allStatusCounts);
        $allPaid    = (int)($allStatusCounts['paid'] ?? 0) + (int)($allStatusCounts['fulfilled'] ?? 0);
        $allPending = (int)($allStatusCounts['pending'] ?? 0) + (int)($allStatusCounts['created'] ?? 0);
        $allFailed  = (int)($allStatusCounts['failed'] ?? 0) + (int)($allStatusCounts['expired'] ?? 0);

        // ====== 3) REVENUE (profit versi kamu) today/month/all-time ======
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

        // ====== 4) DATA PRODUCT ======
        $totalCategories   = (int) Category::query()->count();
        $totalSubCategories = class_exists(SubCategory::class)
            ? (int) SubCategory::query()->count()
            : 0;
        $totalProducts     = (int) Product::query()->count();

        // ====== 5) DATA USER ======
        $totalUsers = (int) User::query()->count();

        $usersByTier = [
            'member' => 0,
            'reseller' => 0,
            'vip' => 0,
        ];
        if ($hasTier) {
            $usersByTier = User::query()
                ->select('tier', DB::raw('COUNT(*) as total'))
                ->groupBy('tier')
                ->pluck('total', 'tier')
                ->toArray();

            // normalize key supaya selalu ada
            $usersByTier = [
                'member'   => (int)($usersByTier['member'] ?? 0),
                'reseller' => (int)($usersByTier['reseller'] ?? 0),
                'vip'      => (int)($usersByTier['vip'] ?? 0),
            ];
        }

        // Total nominal transaksi yang dilakukan user (aggregate)
        // (umumnya revenue total sudah mewakili ini, tapi kamu minta ditampilkan di section user)
        $totalUserTransactionNominal = $revenueTotal;

        // ====== 6) CHART (range + filter modal, sesuai mockup) ======
        $ordersQ = Order::query()->whereBetween('orders.created_at', [$start, $end]);

        $needJoinUsers = ($userTier && $hasTier) || ($q !== '');
        if ($needJoinUsers) {
            $ordersQ->join('users', 'users.id', '=', 'orders.user_id')
                ->select('orders.*');
        }

        if ($status) $ordersQ->where('orders.status', $status);

        if ($userTier && $hasTier) $ordersQ->where('users.tier', $userTier);

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

        // revenue range untuk chart hanya paid/fulfilled
        $revenueQ = (clone $ordersQ)->whereIn('orders.status', $revenueStatuses);

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

        // ====== 7) Response final ======
        return $this->ok([
            'range' => [
                'start' => Carbon::parse($start)->toDateString(),
                'end'   => Carbon::parse($end)->toDateString(),
            ],

            // ALL-TIME transactions (ini yang kamu minta)
            'transactions_all_time' => [
                'total'   => $allTotal,
                'success' => $allPaid,
                'pending' => $allPending,
                'failed'  => $allFailed,
                // raw counts kalau mau debug
                'by_status' => $allStatusCounts,
            ],

            // Revenue/profit summary
            'revenue' => [
                'today' => $revenueToday,
                'month' => $revenueMonth,
                'total' => $revenueTotal,
            ],

            'products' => [
                'categories'   => $totalCategories,
                'subcategories'=> $totalSubCategories,
                'products'     => $totalProducts,
            ],

            'users' => [
                'total' => $totalUsers,
                'by_tier' => $usersByTier,
                'total_transaction_nominal' => $totalUserTransactionNominal,
            ],

            'chart' => [
                'labels'  => $labels,
                'revenue' => $series,
            ],

            'filter_options' => [
                'products' => Product::query()->select('id', 'name')->orderBy('name')->limit(500)->get(),
                'status' => ['created','pending','paid','fulfilled','failed','expired','refunded'],
                'user_tier' => ['member','reseller','vip'],
                'range_presets' => ['today','7d','30d'],
            ],
        ]);
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