<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    private function isPrivileged($user): bool
    {
        return in_array($user->role, ['admin', 'leader'], true);
    }

    private function monthRange(Request $request): array
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

    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        [$from, $to] = $this->monthRange($request);
        $selfFoundId = $this->selfFoundLeadSourceId();

        $currentAssignedQuery = $this->currentAssignmentsBase();

        if (!$this->isPrivileged($user)) {
            $currentAssignedQuery->where('ca.user_id', $user->id);
        } elseif ($request->filled('sale_id')) {
            $currentAssignedQuery->where('ca.user_id', $request->input('sale_id'));
        }

        $this->applyCustomerGroupFilter($currentAssignedQuery, $request, 'customers');

        $assignedInPeriodQuery = DB::table('customer_assignments')
            ->join('customers', 'customers.id', '=', 'customer_assignments.customer_id')
            ->whereBetween('customer_assignments.created_at', [$from, $to]);

        if (!$this->isPrivileged($user)) {
            $assignedInPeriodQuery->where('customer_assignments.user_id', $user->id);
        } elseif ($request->filled('sale_id')) {
            $assignedInPeriodQuery->where('customer_assignments.user_id', $request->input('sale_id'));
        }

        $this->applyCustomerGroupFilter($assignedInPeriodQuery, $request, 'customers');

        $processingQuery = $this->currentAssignmentsBase()
            ->whereNotIn('customers.status', ['contracted', 'lost']);

        if (!$this->isPrivileged($user)) {
            $processingQuery->where('ca.user_id', $user->id);
        } elseif ($request->filled('sale_id')) {
            $processingQuery->where('ca.user_id', $request->input('sale_id'));
        }

        $this->applyCustomerGroupFilter($processingQuery, $request, 'customers');

        $dealQuery = DB::table('customer_deals')
            ->join('customers', 'customers.id', '=', 'customer_deals.customer_id')
            ->whereNotNull('customer_deals.deposit_date')
            ->whereBetween('customer_deals.deposit_date', [$from->toDateString(), $to->toDateString()]);

        if (!$this->isPrivileged($user)) {
            $dealQuery->where('customer_deals.closer_user_id', $user->id);
        } elseif ($request->filled('sale_id')) {
            $dealQuery->where('customer_deals.closer_user_id', $request->input('sale_id'));
        }

        $this->applyCustomerGroupFilter($dealQuery, $request, 'customers');

        $lossQuery = DB::table('customer_losses')
            ->join('customers', 'customers.id', '=', 'customer_losses.customer_id')
            ->whereNotNull('customer_losses.lost_at')
            ->whereBetween('customer_losses.lost_at', [$from, $to]);

        if (!$this->isPrivileged($user)) {
            $lossQuery->where('customer_losses.created_by', $user->id);
        } elseif ($request->filled('sale_id')) {
            $lossQuery->where('customer_losses.created_by', $request->input('sale_id'));
        }

        $this->applyCustomerGroupFilter($lossQuery, $request, 'customers');

        $warningQuery = $this->currentAssignmentsBase();

        if (!$this->isPrivileged($user)) {
            $warningQuery->where('ca.user_id', $user->id);
        } elseif ($request->filled('sale_id')) {
            $warningQuery->where('ca.user_id', $request->input('sale_id'));
        }

        $this->applyCustomerGroupFilter($warningQuery, $request, 'customers');

        $selfFoundCondition = fn ($q) => $q->where('customers.lead_source_id', $selfFoundId);
        $companyLeadCondition = fn ($q) => $q->where(function ($sub) use ($selfFoundId) {
            $sub->whereNull('customers.lead_source_id')
                ->orWhere('customers.lead_source_id', '!=', $selfFoundId);
        });

        $currentAssignedCustomers = (int) (clone $currentAssignedQuery)->count();
        $currentAssignedSelfFound = $selfFoundId ? (int) tap(clone $currentAssignedQuery, $selfFoundCondition)->count() : 0;
        $currentAssignedCompanyLead = $selfFoundId ? (int) tap(clone $currentAssignedQuery, $companyLeadCondition)->count() : $currentAssignedCustomers;

        $assignedInPeriod = (int) (clone $assignedInPeriodQuery)->count();
        $assignedInPeriodSelfFound = $selfFoundId ? (int) tap(clone $assignedInPeriodQuery, $selfFoundCondition)->count() : 0;
        $assignedInPeriodCompanyLead = $selfFoundId ? (int) tap(clone $assignedInPeriodQuery, $companyLeadCondition)->count() : $assignedInPeriod;

        $processingCustomers = (int) (clone $processingQuery)->count();
        $processingSelfFound = $selfFoundId ? (int) tap(clone $processingQuery, $selfFoundCondition)->count() : 0;
        $processingCompanyLead = $selfFoundId ? (int) tap(clone $processingQuery, $companyLeadCondition)->count() : $processingCustomers;

        $monthWonCustomers = (int) (clone $dealQuery)->count();
        $monthWonSelfFound = $selfFoundId ? (int) tap(clone $dealQuery, $selfFoundCondition)->count() : 0;
        $monthWonCompanyLead = $selfFoundId ? (int) tap(clone $dealQuery, $companyLeadCondition)->count() : $monthWonCustomers;

        $monthRevenue = (float) (clone $dealQuery)
            ->sum(DB::raw('COALESCE(customer_deals.final_revenue, customer_deals.net_revenue, 0)'));

        $monthRevenueSelfFound = $selfFoundId
            ? (float) tap(clone $dealQuery, $selfFoundCondition)
                ->sum(DB::raw('COALESCE(customer_deals.final_revenue, customer_deals.net_revenue, 0)'))
            : 0;

        $monthRevenueCompanyLead = $selfFoundId
            ? (float) tap(clone $dealQuery, $companyLeadCondition)
                ->sum(DB::raw('COALESCE(customer_deals.final_revenue, customer_deals.net_revenue, 0)'))
            : $monthRevenue;

        $monthLostCustomers = (int) (clone $lossQuery)->count();
        $monthLostSelfFound = $selfFoundId ? (int) tap(clone $lossQuery, $selfFoundCondition)->count() : 0;
        $monthLostCompanyLead = $selfFoundId ? (int) tap(clone $lossQuery, $companyLeadCondition)->count() : $monthLostCustomers;

        $yellowWarnings = (int) (clone $warningQuery)->where('customers.warning_level', 'yellow')->count();
        $redWarnings = (int) (clone $warningQuery)->where('customers.warning_level', 'red')->count();

        $yellowWarningsSelfFound = $selfFoundId
            ? (int) tap((clone $warningQuery)->where('customers.warning_level', 'yellow'), $selfFoundCondition)->count()
            : 0;

        $yellowWarningsCompanyLead = $selfFoundId
            ? (int) tap((clone $warningQuery)->where('customers.warning_level', 'yellow'), $companyLeadCondition)->count()
            : $yellowWarnings;

        $redWarningsSelfFound = $selfFoundId
            ? (int) tap((clone $warningQuery)->where('customers.warning_level', 'red'), $selfFoundCondition)->count()
            : 0;

        $redWarningsCompanyLead = $selfFoundId
            ? (int) tap((clone $warningQuery)->where('customers.warning_level', 'red'), $companyLeadCondition)->count()
            : $redWarnings;

        $teamMonthRevenue = null;
        if ($this->isPrivileged($user)) {
            $teamRevenueQuery = DB::table('customer_deals')
                ->join('customers', 'customers.id', '=', 'customer_deals.customer_id')
                ->whereNotNull('customer_deals.deposit_date')
                ->whereBetween('customer_deals.deposit_date', [$from->toDateString(), $to->toDateString()]);

            $this->applyCustomerGroupFilter($teamRevenueQuery, $request, 'customers');

            $teamMonthRevenue = (float) $teamRevenueQuery
                ->sum(DB::raw('COALESCE(customer_deals.final_revenue, customer_deals.net_revenue, 0)'));
        }

        return response()->json([
            'current_assigned_customers' => $currentAssignedCustomers,
            'current_assigned_self_found' => $currentAssignedSelfFound,
            'current_assigned_company_lead' => $currentAssignedCompanyLead,

            'assigned_in_period' => $assignedInPeriod,
            'assigned_in_period_self_found' => $assignedInPeriodSelfFound,
            'assigned_in_period_company_lead' => $assignedInPeriodCompanyLead,

            'processing_customers' => $processingCustomers,
            'processing_self_found' => $processingSelfFound,
            'processing_company_lead' => $processingCompanyLead,

            'month_revenue' => $monthRevenue,
            'month_revenue_self_found' => $monthRevenueSelfFound,
            'month_revenue_company_lead' => $monthRevenueCompanyLead,

            'month_won_customers' => $monthWonCustomers,
            'month_won_self_found' => $monthWonSelfFound,
            'month_won_company_lead' => $monthWonCompanyLead,

            'month_lost_customers' => $monthLostCustomers,
            'month_lost_self_found' => $monthLostSelfFound,
            'month_lost_company_lead' => $monthLostCompanyLead,

            'yellow_warnings' => $yellowWarnings,
            'yellow_warnings_self_found' => $yellowWarningsSelfFound,
            'yellow_warnings_company_lead' => $yellowWarningsCompanyLead,

            'red_warnings' => $redWarnings,
            'red_warnings_self_found' => $redWarningsSelfFound,
            'red_warnings_company_lead' => $redWarningsCompanyLead,

            'team_month_revenue' => $teamMonthRevenue,
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
        ]);
    }

    public function revenueDaily(Request $request): JsonResponse
    {
        $user = $request->user();
        [$from, $to] = $this->monthRange($request);

        $query = DB::table('customer_deals')
            ->join('customers', 'customers.id', '=', 'customer_deals.customer_id')
            ->selectRaw('customer_deals.deposit_date as label')
            ->selectRaw('SUM(COALESCE(customer_deals.final_revenue, 0)) as revenue')
            ->whereNotNull('customer_deals.deposit_date')
            ->whereBetween('customer_deals.deposit_date', [$from->toDateString(), $to->toDateString()]);

        $this->applyCustomerGroupFilter($query, $request, 'customers');

        if (!$this->isPrivileged($user)) {
            $query->where('customer_deals.closer_user_id', $user->id);
        } elseif ($request->filled('sale_id')) {
            $query->where('customer_deals.closer_user_id', $request->input('sale_id'));
        }

        $rows = $query
            ->groupBy('customer_deals.deposit_date')
            ->orderBy('customer_deals.deposit_date')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function revenueBySale(Request $request): JsonResponse
    {
        $user = $request->user();
        [$from, $to] = $this->monthRange($request);

        $query = DB::table('customer_deals')
            ->join('customers', 'customers.id', '=', 'customer_deals.customer_id')
            ->join('users', 'users.id', '=', 'customer_deals.closer_user_id')
            ->selectRaw('users.id as user_id, users.name as user_name')
            ->selectRaw('COUNT(customer_deals.id) as total_deals')
            ->selectRaw('SUM(COALESCE(customer_deals.final_revenue, 0)) as revenue')
            ->whereNotNull('customer_deals.deposit_date')
            ->whereBetween('customer_deals.deposit_date', [$from->toDateString(), $to->toDateString()]);

        $this->applyCustomerGroupFilter($query, $request, 'customers');

        if (!$this->isPrivileged($user)) {
            $query->where('customer_deals.closer_user_id', $user->id);
        } elseif ($request->filled('sale_id')) {
            $query->where('customer_deals.closer_user_id', $request->input('sale_id'));
        }

        $rows = $query
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('revenue')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function assignedCurrent(Request $request): JsonResponse
    {
        $user = $request->user();
        $selfFoundId = $this->selfFoundLeadSourceId();

        $query = $this->currentAssignmentsBase()
            ->selectRaw('users.id as user_id, users.name as user_name')
            ->selectRaw('COUNT(customers.id) as total_customers')
            ->selectRaw("
                SUM(
                    CASE
                        WHEN customers.lead_source_id = {$selfFoundId} THEN 1
                        ELSE 0
                    END
                ) as self_found_customers
            ")
            ->selectRaw("
                SUM(
                    CASE
                        WHEN customers.lead_source_id IS NULL OR customers.lead_source_id != {$selfFoundId} THEN 1
                        ELSE 0
                    END
                ) as company_lead_customers
            ");

        $this->applyCustomerGroupFilter($query, $request, 'customers');

        if (!$this->isPrivileged($user)) {
            $query->where('ca.user_id', $user->id);
        } elseif ($request->filled('sale_id')) {
            $query->where('ca.user_id', $request->input('sale_id'));
        }

        if ($request->filled('status')) {
            $query->where('customers.status', $request->input('status'));
        }

        $rows = $query
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('total_customers')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function assignedInPeriod(Request $request): JsonResponse
    {
        $user = $request->user();
        [$from, $to] = $this->monthRange($request);

        $query = DB::table('customer_assignments')
            ->join('customers', 'customers.id', '=', 'customer_assignments.customer_id')
            ->join('users', 'users.id', '=', 'customer_assignments.user_id')
            ->selectRaw('users.id as user_id, users.name as user_name')
            ->selectRaw('COUNT(customer_assignments.id) as assigned_in_period')
            ->whereBetween('customer_assignments.created_at', [$from, $to]);

        $this->applyCustomerGroupFilter($query, $request, 'customers');

        if (!$this->isPrivileged($user)) {
            $query->where('customer_assignments.user_id', $user->id);
        } elseif ($request->filled('sale_id')) {
            $query->where('customer_assignments.user_id', $request->input('sale_id'));
        }

        $rows = $query
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('assigned_in_period')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function topSale(Request $request): JsonResponse
    {
        [$from, $to] = $this->monthRange($request);

        $query = DB::table('customer_deals')
            ->join('customers', 'customers.id', '=', 'customer_deals.customer_id')
            ->join('users', 'users.id', '=', 'customer_deals.closer_user_id')
            ->selectRaw('users.id as user_id, users.name as user_name, users.avatar as avatar')
            ->selectRaw('COUNT(customer_deals.id) as total_deals')
            ->selectRaw('SUM(COALESCE(customer_deals.final_revenue, 0)) as revenue')
            ->whereNotNull('customer_deals.deposit_date')
            ->whereBetween('customer_deals.deposit_date', [$from->toDateString(), $to->toDateString()]);

        $this->applyCustomerGroupFilter($query, $request, 'customers');

        if ($request->filled('sale_id')) {
            $query->where('customer_deals.closer_user_id', $request->input('sale_id'));
        }

        $rows = $query
            ->groupBy('users.id', 'users.name', 'users.avatar')
            ->orderByDesc('revenue')
            ->get();

        $top = $rows->first();

        if (!$top) {
            return response()->json(['data' => null]);
        }

        $totalRevenue = (float) $rows->sum('revenue');
        $contributionPercent = $totalRevenue > 0
            ? round(((float) $top->revenue / $totalRevenue) * 100, 2)
            : 0;

        return response()->json([
            'data' => [
                'user_id' => $top->user_id,
                'user_name' => $top->user_name,
                'avatar' => $top->avatar,
                'total_deals' => (int) $top->total_deals,
                'revenue' => (float) $top->revenue,
                'contribution_percent' => $contributionPercent,
            ],
        ]);
    }

    public function myRank(Request $request): JsonResponse
    {
        $user = $request->user();
        [$from, $to] = $this->monthRange($request);

        $query = DB::table('customer_deals')
            ->join('customers', 'customers.id', '=', 'customer_deals.customer_id')
            ->join('users', 'users.id', '=', 'customer_deals.closer_user_id')
            ->selectRaw('users.id as user_id, users.name as user_name')
            ->selectRaw('COUNT(customer_deals.id) as total_deals')
            ->selectRaw('SUM(COALESCE(customer_deals.final_revenue, 0)) as revenue')
            ->whereNotNull('customer_deals.deposit_date')
            ->whereBetween('customer_deals.deposit_date', [$from->toDateString(), $to->toDateString()]);

        $this->applyCustomerGroupFilter($query, $request, 'customers');

        $rows = $query
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('revenue')
            ->get()
            ->values();

        $index = $rows->search(fn ($row) => (int) $row->user_id === (int) $user->id);

        if ($index === false) {
            return response()->json([
                'data' => [
                    'rank' => null,
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'total_deals' => 0,
                    'revenue' => 0,
                ],
            ]);
        }

        $row = $rows[$index];

        return response()->json([
            'data' => [
                'rank' => $index + 1,
                'user_id' => $row->user_id,
                'user_name' => $row->user_name,
                'total_deals' => (int) $row->total_deals,
                'revenue' => (float) $row->revenue,
            ],
        ]);
    }
    public function rankingSale(Request $request): JsonResponse
    {
        [$from, $to] = $this->monthRange($request);

        $query = DB::table('customer_deals')
            ->join('customers', 'customers.id', '=', 'customer_deals.customer_id')
            ->join('users', 'users.id', '=', 'customer_deals.closer_user_id')
            ->selectRaw('
                users.id as user_id,
                users.name as user_name,
                users.avatar as avatar,
                COUNT(customer_deals.id) as total_deals,
                SUM(COALESCE(customer_deals.final_revenue, 0)) as revenue
            ')
            ->whereNotNull('customer_deals.deposit_date')
            ->whereBetween('customer_deals.deposit_date', [$from->toDateString(), $to->toDateString()]);

        $this->applyCustomerGroupFilter($query, $request, 'customers');

        // Chỉ filter sale_id nếu user chọn rõ 1 sale.
        // KHÔNG ép sale chỉ thấy mình ở leaderboard.
        if ($request->filled('sale_id')) {
            $query->where('customer_deals.closer_user_id', $request->input('sale_id'));
        }

        $rows = $query
            ->groupBy('users.id', 'users.name', 'users.avatar')
            ->orderByDesc('revenue')
            ->get()
            ->values();

        $totalRevenue = (float) $rows->sum('revenue');

        $ranked = $rows->map(function ($row, $index) use ($totalRevenue) {
            return [
                'rank' => $index + 1,
                'user_id' => $row->user_id,
                'user_name' => $row->user_name,
                'avatar' => $row->avatar,
                'total_deals' => (int) $row->total_deals,
                'revenue' => (float) $row->revenue,
                'percent' => $totalRevenue > 0
                    ? round(($row->revenue / $totalRevenue) * 100, 2)
                    : 0,
            ];
        });

        return response()->json([
            'data' => $ranked,
            'total_revenue' => $totalRevenue,
        ]);
    }
}