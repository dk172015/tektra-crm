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

        // 1. Current assigned
        $currentAssignedQuery = $this->currentAssignmentsBase();
        $this->applyCustomerGroupFilter($currentAssignedQuery, $request, 'customers');

        if (!$this->isPrivileged($user)) {
            $currentAssignedQuery->where('ca.user_id', $user->id);
        } elseif ($request->filled('sale_id')) {
            $currentAssignedQuery->where('ca.user_id', $request->input('sale_id'));
        }

        // 2. Assigned in period
        $assignedInPeriodQuery = DB::table('customer_assignments')
            ->join('customers', 'customers.id', '=', 'customer_assignments.customer_id')
            ->whereBetween('customer_assignments.created_at', [$from, $to]);

        $this->applyCustomerGroupFilter($assignedInPeriodQuery, $request, 'customers');

        if (!$this->isPrivileged($user)) {
            $assignedInPeriodQuery->where('customer_assignments.user_id', $user->id);
        } elseif ($request->filled('sale_id')) {
            $assignedInPeriodQuery->where('customer_assignments.user_id', $request->input('sale_id'));
        }

        // 3. Processing
        $processingQuery = $this->currentAssignmentsBase()
            ->whereNotIn('customers.status', ['contracted', 'lost']);

        $this->applyCustomerGroupFilter($processingQuery, $request, 'customers');

        if (!$this->isPrivileged($user)) {
            $processingQuery->where('ca.user_id', $user->id);
        } elseif ($request->filled('sale_id')) {
            $processingQuery->where('ca.user_id', $request->input('sale_id'));
        }

        // 4. Won / Revenue
        $dealQuery = DB::table('customer_deals')
            ->join('customers', 'customers.id', '=', 'customer_deals.customer_id')
            ->whereNotNull('customer_deals.deposit_date')
            ->whereBetween('customer_deals.deposit_date', [$from->toDateString(), $to->toDateString()]);

        $this->applyCustomerGroupFilter($dealQuery, $request, 'customers');

        if (!$this->isPrivileged($user)) {
            $dealQuery->where('customer_deals.closer_user_id', $user->id);
        } elseif ($request->filled('sale_id')) {
            $dealQuery->where('customer_deals.closer_user_id', $request->input('sale_id'));
        }

        // 5. Lost
        $lossQuery = DB::table('customer_losses')
            ->join('customers', 'customers.id', '=', 'customer_losses.customer_id')
            ->whereNotNull('customer_losses.lost_at')
            ->whereBetween('customer_losses.lost_at', [$from, $to]);

        $this->applyCustomerGroupFilter($lossQuery, $request, 'customers');

        if (!$this->isPrivileged($user)) {
            $lossQuery->where('customer_losses.created_by', $user->id);
        } elseif ($request->filled('sale_id')) {
            $lossQuery->where('customer_losses.created_by', $request->input('sale_id'));
        }

        // 6. Warnings
        $warningQuery = $this->currentAssignmentsBase();
        $this->applyCustomerGroupFilter($warningQuery, $request, 'customers');

        if (!$this->isPrivileged($user)) {
            $warningQuery->where('ca.user_id', $user->id);
        } elseif ($request->filled('sale_id')) {
            $warningQuery->where('ca.user_id', $request->input('sale_id'));
        }

        $yellowWarnings = (clone $warningQuery)
            ->where('customers.warning_level', 'yellow')
            ->count();

        $redWarnings = (clone $warningQuery)
            ->where('customers.warning_level', 'red')
            ->count();

        $monthRevenue = (float) (clone $dealQuery)
            ->sum(DB::raw('COALESCE(customer_deals.final_revenue, customer_deals.net_revenue, 0)'));

        $monthWonCustomers = (int) (clone $dealQuery)->count();
        $monthLostCustomers = (int) (clone $lossQuery)->count();
        $assignedInPeriod = (int) (clone $assignedInPeriodQuery)->count();

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
            'current_assigned_customers' => (int) $currentAssignedQuery->count(),
            'assigned_in_period' => $assignedInPeriod,
            'processing_customers' => (int) $processingQuery->count(),
            'month_revenue' => $monthRevenue,
            'team_month_revenue' => $teamMonthRevenue,
            'month_won_customers' => $monthWonCustomers,
            'month_lost_customers' => $monthLostCustomers,
            'yellow_warnings' => $yellowWarnings,
            'red_warnings' => $redWarnings,
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
            ->selectRaw('SUM(COALESCE(customer_deals.final_revenue, customer_deals.net_revenue, 0)) as revenue')
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
            ->selectRaw('SUM(COALESCE(customer_deals.final_revenue, customer_deals.net_revenue, 0)) as revenue')
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
        $user = $request->user();
        [$from, $to] = $this->monthRange($request);

        $rows = DB::table('customer_deals')
            ->join('customers', 'customers.id', '=', 'customer_deals.customer_id')
            ->join('users', 'users.id', '=', 'customer_deals.closer_user_id')
            ->selectRaw('users.id as user_id, users.name as user_name')
            ->selectRaw('COUNT(customer_deals.id) as total_deals')
            ->selectRaw('SUM(COALESCE(customer_deals.final_revenue, customer_deals.net_revenue, 0)) as revenue')
            ->whereNotNull('customer_deals.deposit_date')
            ->whereBetween('customer_deals.deposit_date', [$from->toDateString(), $to->toDateString()])
            ->when(!$this->isPrivileged($user), fn ($q) => $q->where('customer_deals.closer_user_id', $user->id))
            ->when($this->isPrivileged($user) && $request->filled('sale_id'), fn ($q) => $q->where('customer_deals.closer_user_id', $request->input('sale_id')))
            ->tap(fn ($q) => $this->applyCustomerGroupFilter($q, $request, 'customers'))
            ->groupBy('users.id', 'users.name')
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

        $rows = DB::table('customer_deals')
            ->join('customers', 'customers.id', '=', 'customer_deals.customer_id')
            ->join('users', 'users.id', '=', 'customer_deals.closer_user_id')
            ->selectRaw('users.id as user_id, users.name as user_name')
            ->selectRaw('COUNT(customer_deals.id) as total_deals')
            ->selectRaw('SUM(COALESCE(customer_deals.final_revenue, customer_deals.net_revenue, 0)) as revenue')
            ->whereNotNull('customer_deals.deposit_date')
            ->whereBetween('customer_deals.deposit_date', [$from->toDateString(), $to->toDateString()])
            ->tap(fn ($q) => $this->applyCustomerGroupFilter($q, $request, 'customers'))
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
}