<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RevenueAnalyticsController extends Controller
{
    private function isPrivileged($user): bool
    {
        return in_array($user->role, ['admin', 'leader'], true);
    }

    private function range(Request $request): array
    {
        $now = Carbon::now();

        $from = $request->filled('date_from')
            ? Carbon::parse($request->input('date_from'))->startOfDay()
            : $now->copy()->startOfMonth();

        $to = $request->filled('date_to')
            ? Carbon::parse($request->input('date_to'))->endOfDay()
            : $now->copy()->endOfMonth();

        return [$from, $to];
    }

    private function baseDealQuery(Request $request)
    {
        $user = $request->user();
        [$from, $to] = $this->range($request);

        $query = DB::table('customer_deals')
            ->whereNotNull('deposit_date')
            ->whereBetween('deposit_date', [$from->toDateString(), $to->toDateString()]);

        if (!$this->isPrivileged($user)) {
            $query->where('closer_user_id', $user->id);
        }

        return [$query, $from, $to];
    }

    public function summary(Request $request): JsonResponse
    {
        [$query] = $this->baseDealQuery($request);

        $totalRevenue = (float) (clone $query)->sum(DB::raw('COALESCE(final_revenue, net_revenue, 0)'));
        $totalDeals = (int) (clone $query)->count();
        $avgRevenue = $totalDeals > 0 ? round($totalRevenue / $totalDeals, 2) : 0;

        $topDeal = (clone $query)
            ->select('id', 'building_name', 'deposit_date')
            ->selectRaw('COALESCE(final_revenue, net_revenue, 0) as revenue')
            ->orderByDesc('revenue')
            ->first();

        return response()->json([
            'total_revenue' => $totalRevenue,
            'total_deals' => $totalDeals,
            'avg_revenue' => $avgRevenue,
            'top_deal' => $topDeal,
        ]);
    }

    public function trend(Request $request): JsonResponse
    {
        [$query] = $this->baseDealQuery($request);

        $rows = $query
            ->selectRaw('deposit_date as label')
            ->selectRaw('SUM(COALESCE(final_revenue, net_revenue, 0)) as revenue')
            ->groupBy('deposit_date')
            ->orderBy('deposit_date')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function bySale(Request $request): JsonResponse
    {
        [$query] = $this->baseDealQuery($request);

        $rows = $query
            ->join('users', 'users.id', '=', 'customer_deals.closer_user_id')
            ->selectRaw('users.id as user_id, users.name as user_name')
            ->selectRaw('COUNT(customer_deals.id) as total_deals')
            ->selectRaw('SUM(COALESCE(customer_deals.final_revenue, customer_deals.net_revenue, 0)) as revenue')
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('revenue')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function byBuilding(Request $request): JsonResponse
    {
        [$query] = $this->baseDealQuery($request);

        $rows = $query
            ->selectRaw("COALESCE(building_name, 'Chưa rõ') as building_name")
            ->selectRaw('COUNT(id) as total_deals')
            ->selectRaw('SUM(COALESCE(final_revenue, net_revenue, 0)) as revenue')
            ->groupBy('building_name')
            ->orderByDesc('revenue')
            ->limit(20)
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function bySource(Request $request): JsonResponse
    {
        [$query] = $this->baseDealQuery($request);

        $rows = $query
            ->join('customers', 'customers.id', '=', 'customer_deals.customer_id')
            ->leftJoin('lead_sources', 'lead_sources.id', '=', 'customers.lead_source_id')
            ->selectRaw("COALESCE(lead_sources.name, 'Không rõ nguồn') as source_name")
            ->selectRaw('COUNT(customer_deals.id) as total_deals')
            ->selectRaw('SUM(COALESCE(customer_deals.final_revenue, customer_deals.net_revenue, 0)) as revenue')
            ->groupBy('lead_sources.name')
            ->orderByDesc('revenue')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function topDeals(Request $request): JsonResponse
    {
        [$query] = $this->baseDealQuery($request);

        $rows = $query
            ->join('customers', 'customers.id', '=', 'customer_deals.customer_id')
            ->leftJoin('users', 'users.id', '=', 'customer_deals.closer_user_id')
            ->selectRaw('customer_deals.id')
            ->selectRaw('customers.company_name')
            ->selectRaw('customer_deals.building_name')
            ->selectRaw('users.name as closer_name')
            ->selectRaw('customer_deals.deposit_date')
            ->selectRaw('COALESCE(customer_deals.final_revenue, customer_deals.net_revenue, 0) as revenue')
            ->orderByDesc('revenue')
            ->limit(10)
            ->get();

        return response()->json(['data' => $rows]);
    }
}