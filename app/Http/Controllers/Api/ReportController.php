<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerDeal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function revenueSummary(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = CustomerDeal::query();

        if (!$user->isAdmin()) {
            $query->where('closer_user_id', $user->id);
        }

        $todayRevenue = (clone $query)
            ->whereDate('deposit_date', today())
            ->sum(DB::raw('COALESCE(final_revenue, net_revenue, 0)'));

        $monthRevenue = (clone $query)
            ->whereYear('deposit_date', now()->year)
            ->whereMonth('deposit_date', now()->month)
            ->sum(DB::raw('COALESCE(final_revenue, net_revenue, 0)'));

        $yearRevenue = (clone $query)
            ->whereYear('deposit_date', now()->year)
            ->sum(DB::raw('COALESCE(final_revenue, net_revenue, 0)'));

        $totalRevenue = (clone $query)
            ->sum(DB::raw('COALESCE(final_revenue, net_revenue, 0)'));

        return response()->json([
            'today_revenue' => (float) $todayRevenue,
            'month_revenue' => (float) $monthRevenue,
            'year_revenue' => (float) $yearRevenue,
            'total_revenue' => (float) $totalRevenue,
        ]);
    }

    public function revenueByPeriod(Request $request): JsonResponse
    {
        $user = $request->user();

        $type = $request->input('type', 'month');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        $query = CustomerDeal::query()->whereNotNull('deposit_date');

        if (!$user->isAdmin()) {
            $query->where('closer_user_id', $user->id);
        }

        if ($dateFrom) {
            $query->whereDate('deposit_date', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->whereDate('deposit_date', '<=', $dateTo);
        }

        if ($type === 'day') {
            $rows = $query
                ->selectRaw("DATE(deposit_date) as label")
                ->selectRaw("SUM(COALESCE(final_revenue, net_revenue, 0)) as revenue")
                ->groupByRaw("DATE(deposit_date)")
                ->orderByRaw("DATE(deposit_date)")
                ->get();
        } elseif ($type === 'year') {
            $rows = $query
                ->selectRaw("YEAR(deposit_date) as label")
                ->selectRaw("SUM(COALESCE(final_revenue, net_revenue, 0)) as revenue")
                ->groupByRaw("YEAR(deposit_date)")
                ->orderByRaw("YEAR(deposit_date)")
                ->get();
        } else {
            $rows = $query
                ->selectRaw("DATE_FORMAT(deposit_date, '%Y-%m') as label")
                ->selectRaw("SUM(COALESCE(final_revenue, net_revenue, 0)) as revenue")
                ->groupByRaw("DATE_FORMAT(deposit_date, '%Y-%m')")
                ->orderByRaw("DATE_FORMAT(deposit_date, '%Y-%m')")
                ->get();
        }

        return response()->json([
            'data' => $rows,
        ]);
    }

    public function revenueBySale(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = CustomerDeal::query()
            ->join('users', 'users.id', '=', 'customer_deals.closer_user_id')
            ->selectRaw('users.id as user_id, users.name as user_name')
            ->selectRaw('COUNT(customer_deals.id) as total_deals')
            ->selectRaw('SUM(COALESCE(customer_deals.final_revenue, customer_deals.net_revenue, 0)) as total_revenue')
            ->whereNotNull('customer_deals.deposit_date');

        if (!$user->isAdmin()) {
            $query->where('customer_deals.closer_user_id', $user->id);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('customer_deals.deposit_date', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('customer_deals.deposit_date', '<=', $request->input('date_to'));
        }

        $rows = $query
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('total_revenue')
            ->get();

        return response()->json([
            'data' => $rows,
        ]);
    }

    public function customerSummary(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = $this->currentAssignmentsBase();

        if (!$this->isPrivileged($user)) {
            $query->where('ca.user_id', $user->id);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('customers.created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('customers.created_at', '<=', $request->input('date_to'));
        }

        $todayCustomers = (clone $query)
            ->whereDate('customers.created_at', today())
            ->count();

        $monthCustomers = (clone $query)
            ->whereYear('customers.created_at', now()->year)
            ->whereMonth('customers.created_at', now()->month)
            ->count();

        $yearCustomers = (clone $query)
            ->whereYear('customers.created_at', now()->year)
            ->count();

        $totalCustomers = (clone $query)->count();

        return response()->json([
            'today_customers' => $todayCustomers,
            'month_customers' => $monthCustomers,
            'year_customers' => $yearCustomers,
            'total_customers' => $totalCustomers,
        ]);
    }

    public function customerByStatus(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = $this->currentAssignmentsBase()
            ->selectRaw('customers.status, COUNT(customers.id) as total');

        if (!$this->isPrivileged($user)) {
            $query->where('ca.user_id', $user->id);
        }

        if ($request->filled('sale_id') && $this->isPrivileged($user)) {
            $query->where('ca.user_id', $request->input('sale_id'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('customers.created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('customers.created_at', '<=', $request->input('date_to'));
        }

        $rows = $query
            ->groupBy('customers.status')
            ->orderBy('customers.status')
            ->get();

        return response()->json([
            'data' => $rows,
        ]);
    }

    public function customerBySale(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = $this->currentAssignmentsBase()
            ->selectRaw('users.id as user_id, users.name as user_name')
            ->selectRaw('COUNT(customers.id) as total_customers');

        if (!$this->isPrivileged($user)) {
            $query->where('ca.user_id', $user->id);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('customers.created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('customers.created_at', '<=', $request->input('date_to'));
        }

        $rows = $query
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('total_customers')
            ->get();

        return response()->json([
            'data' => $rows,
        ]);
    }
    private function isPrivileged($user): bool
    {
        return in_array($user->role, ['admin', 'leader'], true);
    }

    private function latestAssignmentIdsSubquery()
    {
        return DB::table('customer_assignments as ca1')
            ->selectRaw('MAX(ca1.id) as id')
            ->groupBy('ca1.customer_id');
    }

    private function currentAssignmentsBase()
    {
        return DB::table('customer_assignments as ca')
            ->joinSub($this->latestAssignmentIdsSubquery(), 'latest_ca', function ($join) {
                $join->on('latest_ca.id', '=', 'ca.id');
            })
            ->join('customers', 'customers.id', '=', 'ca.customer_id')
            ->join('users', 'users.id', '=', 'ca.user_id');
    }
}