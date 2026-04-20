<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RevenueReportController extends Controller
{
    private function monthRange(Request $request): array
    {
        $from = $request->filled('date_from')
            ? Carbon::parse($request->input('date_from'))->startOfDay()
            : now()->startOfMonth();

        $to = $request->filled('date_to')
            ? Carbon::parse($request->input('date_to'))->endOfDay()
            : now()->endOfMonth();

        return [$from, $to];
    }

    private function selfFoundLeadSourceId(): ?int
    {
        return DB::table('lead_sources')
            ->where('code', 'sales_self_gen')
            ->value('id');
    }

    private function applyCustomerGroupFilter($query, Request $request, string $customerTable = 'customers')
    {
        $group = $request->input('customer_group');
        $selfFoundId = $this->selfFoundLeadSourceId();

        if (!$group || !$selfFoundId) {
            return $query;
        }

        if ($group === 'self_found') {
            $query->where("{$customerTable}.lead_source_id", $selfFoundId);
        }

        if ($group === 'company_lead') {
            $query->where(function ($q) use ($customerTable, $selfFoundId) {
                $q->whereNull("{$customerTable}.lead_source_id")
                    ->orWhere("{$customerTable}.lead_source_id", '!=', $selfFoundId);
            });
        }

        return $query;
    }

    public function summary(Request $request): JsonResponse
    {
        [$from, $to] = $this->monthRange($request);
        $selfFoundId = $this->selfFoundLeadSourceId();

        $query = DB::table('customer_deals')
            ->join('customers', 'customers.id', '=', 'customer_deals.customer_id')
            ->whereNotNull('customer_deals.deposit_date')
            ->whereBetween('customer_deals.deposit_date', [$from->toDateString(), $to->toDateString()]);

        $this->applyCustomerGroupFilter($query, $request, 'customers');

        $selfFoundQuery = clone $query;
        $companyLeadQuery = clone $query;

        $revenue = (float) (clone $query)->sum(DB::raw('COALESCE(customer_deals.net_revenue, 0)'));
        $dealCount = (int) (clone $query)->count();

        $selfFoundRevenue = $selfFoundId
            ? (float) $selfFoundQuery->where('customers.lead_source_id', $selfFoundId)
                ->sum(DB::raw('COALESCE(customer_deals.net_revenue, 0)'))
            : 0;

        $companyLeadRevenue = $selfFoundId
            ? (float) $companyLeadQuery
                ->where(function ($q) use ($selfFoundId) {
                    $q->whereNull('customers.lead_source_id')
                        ->orWhere('customers.lead_source_id', '!=', $selfFoundId);
                })
                ->sum(DB::raw('COALESCE(customer_deals.net_revenue, 0)'))
            : $revenue;

        $selfFoundDeals = $selfFoundId
            ? (int) (clone $query)->where('customers.lead_source_id', $selfFoundId)->count()
            : 0;

        $companyLeadDeals = $selfFoundId
            ? (int) (clone $query)
                ->where(function ($q) use ($selfFoundId) {
                    $q->whereNull('customers.lead_source_id')
                        ->orWhere('customers.lead_source_id', '!=', $selfFoundId);
                })
                ->count()
            : $dealCount;

        return response()->json([
            'revenue' => $revenue,
            'deal_count' => $dealCount,
            'self_found_revenue' => $selfFoundRevenue,
            'company_lead_revenue' => $companyLeadRevenue,
            'self_found_deals' => $selfFoundDeals,
            'company_lead_deals' => $companyLeadDeals,
        ]);
    }

    public function deals(Request $request): JsonResponse
    {
        [$from, $to] = $this->monthRange($request);

        $query = DB::table('customer_deals')
            ->join('customers', 'customers.id', '=', 'customer_deals.customer_id')
            ->leftJoin('users', 'users.id', '=', 'customer_deals.closer_user_id')
            ->leftJoin('lead_sources', 'lead_sources.id', '=', 'customers.lead_source_id')
            ->whereNotNull('customer_deals.deposit_date')
            ->whereBetween('customer_deals.deposit_date', [$from->toDateString(), $to->toDateString()]);

        $this->applyCustomerGroupFilter($query, $request, 'customers');

        if ($request->filled('keyword')) {
            $keyword = trim($request->input('keyword'));
            $query->where(function ($q) use ($keyword) {
                $q->where('customers.company_name', 'like', "%{$keyword}%")
                    ->orWhere('customers.contact_name', 'like', "%{$keyword}%")
                    ->orWhere('customers.phone', 'like', "%{$keyword}%");
            });
        }

        if ($request->filled('sale_id')) {
            $query->where('customer_deals.closer_user_id', $request->input('sale_id'));
        }

        $rows = $query
            ->selectRaw('customer_deals.id')
            ->selectRaw('customers.company_name')
            ->selectRaw('customers.contact_name')
            ->selectRaw('customers.phone')
            ->selectRaw('users.name as sale_name')
            ->selectRaw('lead_sources.code as lead_source_code')
            ->selectRaw('customer_deals.deposit_date')
            ->selectRaw('COALESCE(customer_deals.net_revenue, 0) as revenue')
            ->orderByDesc('customer_deals.deposit_date')
            ->paginate((int) $request->input('per_page', 20));

        return response()->json($rows);
    }
}