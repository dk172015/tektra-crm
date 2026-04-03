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

    /**
     * Lấy assignment mới nhất của mỗi customer.
     */
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

        $dealQuery = DB::table('customer_deals')
            ->whereNotNull('deposit_date')
            ->whereBetween('deposit_date', [$from->toDateString(), $to->toDateString()]);

        if (!$this->isPrivileged($user)) {
            $dealQuery->where('closer_user_id', $user->id);
        }

        $monthRevenue = (float) (clone $dealQuery)->sum(DB::raw('COALESCE(final_revenue, net_revenue, 0)'));
        $monthWonCustomers = (int) (clone $dealQuery)->count();

        $teamMonthRevenue = null;
        if ($this->isPrivileged($user)) {
            $teamMonthRevenue = (float) DB::table('customer_deals')
                ->whereNotNull('deposit_date')
                ->whereBetween('deposit_date', [$from->toDateString(), $to->toDateString()])
                ->sum(DB::raw('COALESCE(final_revenue, net_revenue, 0)'));
        }

        $lossQuery = DB::table('customer_losses')
            ->whereNotNull('lost_at')
            ->whereBetween('lost_at', [$from, $to]);

        if (!$this->isPrivileged($user)) {
            $lossQuery->where('created_by', $user->id);
        }

        $monthLostCustomers = (int) (clone $lossQuery)->count();

        $assignmentQuery = $this->currentAssignmentsBase()
            ->whereNotIn('customers.status', ['contracted', 'lost']);

        if (!$this->isPrivileged($user)) {
            $assignmentQuery->where('ca.user_id', $user->id);
        }

        $myAssignedCustomers = (int) (clone $assignmentQuery)->count();

        $warningQuery = $this->currentAssignmentsBase()
            ->whereNotNull('customers.warning_updated_at')
            ->whereBetween('customers.warning_updated_at', [$from, $to]);

        if (!$this->isPrivileged($user)) {
            $warningQuery->where('ca.user_id', $user->id);
        }

        $yellowWarnings = (int) (clone $warningQuery)
            ->where('customers.warning_level', 'yellow')
            ->count();

        $redWarnings = (int) (clone $warningQuery)
            ->where('customers.warning_level', 'red')
            ->count();

        return response()->json([
            'month_revenue' => $monthRevenue,
            'team_month_revenue' => $teamMonthRevenue,
            'month_won_customers' => $monthWonCustomers,
            'month_lost_customers' => $monthLostCustomers,
            'my_assigned_customers' => $myAssignedCustomers,
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
            ->selectRaw('deposit_date as label')
            ->selectRaw('SUM(COALESCE(final_revenue, net_revenue, 0)) as revenue')
            ->whereNotNull('deposit_date')
            ->whereBetween('deposit_date', [$from->toDateString(), $to->toDateString()]);

        if (!$this->isPrivileged($user)) {
            $query->where('closer_user_id', $user->id);
        }

        $rows = $query
            ->groupBy('deposit_date')
            ->orderBy('deposit_date')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function revenueBySale(Request $request): JsonResponse
    {
        $user = $request->user();
        [$from, $to] = $this->monthRange($request);

        $query = DB::table('customer_deals')
            ->join('users', 'users.id', '=', 'customer_deals.closer_user_id')
            ->selectRaw('users.id as user_id, users.name as user_name')
            ->selectRaw('COUNT(customer_deals.id) as total_deals')
            ->selectRaw('SUM(COALESCE(customer_deals.final_revenue, customer_deals.net_revenue, 0)) as revenue')
            ->whereNotNull('customer_deals.deposit_date')
            ->whereBetween('customer_deals.deposit_date', [$from->toDateString(), $to->toDateString()]);

        if (!$this->isPrivileged($user)) {
            $query->where('customer_deals.closer_user_id', $user->id);
        }

        $rows = $query
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('revenue')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function customersBySale(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = $this->currentAssignmentsBase()
            ->selectRaw('users.id as user_id, users.name as user_name')
            ->selectRaw('COUNT(customers.id) as total_customers')
            ->whereNotIn('customers.status', ['contracted', 'lost']);

        if (!$this->isPrivileged($user)) {
            $query->where('ca.user_id', $user->id);
        }

        $rows = $query
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('total_customers')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function pipelineResult(Request $request): JsonResponse
    {
        $user = $request->user();
        [$from, $to] = $this->monthRange($request);

        $openQuery = $this->currentAssignmentsBase()
            ->whereNotIn('customers.status', ['contracted', 'lost']);

        if (!$this->isPrivileged($user)) {
            $openQuery->where('ca.user_id', $user->id);
        }

        $openCustomers = (int) (clone $openQuery)->count();

        $wonQuery = DB::table('customer_deals')
            ->whereNotNull('deposit_date')
            ->whereBetween('deposit_date', [$from->toDateString(), $to->toDateString()]);

        if (!$this->isPrivileged($user)) {
            $wonQuery->where('closer_user_id', $user->id);
        }

        $wonCustomers = (int) (clone $wonQuery)->count();

        $lostQuery = DB::table('customer_losses')
            ->whereNotNull('lost_at')
            ->whereBetween('lost_at', [$from, $to]);

        if (!$this->isPrivileged($user)) {
            $lostQuery->where('created_by', $user->id);
        }

        $lostCustomers = (int) (clone $lostQuery)->count();

        return response()->json([
            'open_customers' => $openCustomers,
            'won_customers' => $wonCustomers,
            'lost_customers' => $lostCustomers,
        ]);
    }

    public function topSale(Request $request): JsonResponse
    {
        $user = $request->user();
        [$from, $to] = $this->monthRange($request);

        $rows = DB::table('customer_deals')
            ->join('users', 'users.id', '=', 'customer_deals.closer_user_id')
            ->selectRaw('users.id as user_id, users.name as user_name')
            ->selectRaw('COUNT(customer_deals.id) as total_deals')
            ->selectRaw('SUM(COALESCE(customer_deals.final_revenue, customer_deals.net_revenue, 0)) as revenue')
            ->whereNotNull('customer_deals.deposit_date')
            ->whereBetween('customer_deals.deposit_date', [$from->toDateString(), $to->toDateString()])
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
                'visible_for_user' => $this->isPrivileged($user) || true,
            ],
        ]);
    }

    public function myRank(Request $request): JsonResponse
    {
        $user = $request->user();
        [$from, $to] = $this->monthRange($request);

        $rows = DB::table('customer_deals')
            ->join('users', 'users.id', '=', 'customer_deals.closer_user_id')
            ->selectRaw('users.id as user_id, users.name as user_name')
            ->selectRaw('COUNT(customer_deals.id) as total_deals')
            ->selectRaw('SUM(COALESCE(customer_deals.final_revenue, customer_deals.net_revenue, 0)) as revenue')
            ->whereNotNull('customer_deals.deposit_date')
            ->whereBetween('customer_deals.deposit_date', [$from->toDateString(), $to->toDateString()])
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
    public function conversionBySale(Request $request): JsonResponse
{
    $user = $request->user();
    [$from, $to] = $this->monthRange($request);

    $assignedSub = $this->currentAssignmentsBase()
        ->selectRaw('users.id as user_id, users.name as user_name, COUNT(customers.id) as assigned_customers')
        ->whereNotIn('customers.status', ['contracted', 'lost']);

    if (!$this->isPrivileged($user)) {
        $assignedSub->where('ca.user_id', $user->id);
    }

    $assignedRows = $assignedSub
        ->groupBy('users.id', 'users.name')
        ->get()
        ->keyBy('user_id');

    $wonRows = DB::table('customer_deals')
        ->join('users', 'users.id', '=', 'customer_deals.closer_user_id')
        ->selectRaw('users.id as user_id, COUNT(customer_deals.id) as won_customers')
        ->whereNotNull('customer_deals.deposit_date')
        ->whereBetween('customer_deals.deposit_date', [$from->toDateString(), $to->toDateString()])
        ->when(!$this->isPrivileged($user), fn ($q) => $q->where('customer_deals.closer_user_id', $user->id))
        ->groupBy('users.id')
        ->get()
        ->keyBy('user_id');

    $lostRows = DB::table('customer_losses')
        ->join('users', 'users.id', '=', 'customer_losses.created_by')
        ->selectRaw('users.id as user_id, COUNT(customer_losses.id) as lost_customers')
        ->whereNotNull('customer_losses.lost_at')
        ->whereBetween('customer_losses.lost_at', [$from, $to])
        ->when(!$this->isPrivileged($user), fn ($q) => $q->where('customer_losses.created_by', $user->id))
        ->groupBy('users.id')
        ->get()
        ->keyBy('user_id');

    $result = collect($assignedRows)->map(function ($row, $userId) use ($wonRows, $lostRows) {
        $assigned = (int) $row->assigned_customers;
        $won = (int) ($wonRows[$userId]->won_customers ?? 0);
        $lost = (int) ($lostRows[$userId]->lost_customers ?? 0);

        return [
            'user_id' => (int) $row->user_id,
            'user_name' => $row->user_name,
            'assigned_customers' => $assigned,
            'won_customers' => $won,
            'lost_customers' => $lost,
            'conversion_rate' => $assigned > 0 ? round(($won / $assigned) * 100, 2) : 0,
        ];
    })->values()->sortByDesc('conversion_rate')->values();

    return response()->json(['data' => $result]);
}

public function sourcePerformance(Request $request): JsonResponse
{
    $user = $request->user();
    [$from, $to] = $this->monthRange($request);

    $query = DB::table('customers')
        ->leftJoin('lead_sources', 'lead_sources.id', '=', 'customers.lead_source_id')
        ->leftJoin('customer_deals', function ($join) use ($from, $to) {
            $join->on('customer_deals.customer_id', '=', 'customers.id')
                ->whereBetween('customer_deals.deposit_date', [$from->toDateString(), $to->toDateString()]);
        })
        ->selectRaw("COALESCE(lead_sources.name, 'Không rõ nguồn') as source_name")
        ->selectRaw('COUNT(DISTINCT customers.id) as total_customers')
        ->selectRaw('COUNT(DISTINCT customer_deals.id) as total_deals')
        ->selectRaw('SUM(COALESCE(customer_deals.final_revenue, customer_deals.net_revenue, 0)) as revenue');

    if (!$this->isPrivileged($user)) {
        $query->where('customers.created_by', $user->id);
    }

    $rows = $query
        ->groupBy('lead_sources.name')
        ->orderByDesc('revenue')
        ->get();

    return response()->json(['data' => $rows]);
}

public function buildingPerformance(Request $request): JsonResponse
{
    $user = $request->user();
    [$from, $to] = $this->monthRange($request);

    $query = DB::table('customer_deals')
        ->selectRaw("COALESCE(building_name, 'Chưa rõ') as building_name")
        ->selectRaw('COUNT(id) as total_deals')
        ->selectRaw('SUM(COALESCE(final_revenue, net_revenue, 0)) as revenue')
        ->whereNotNull('deposit_date')
        ->whereBetween('deposit_date', [$from->toDateString(), $to->toDateString()]);

    if (!$this->isPrivileged($user)) {
        $query->where('closer_user_id', $user->id);
    }

    $rows = $query
        ->groupBy('building_name')
        ->orderByDesc('revenue')
        ->limit(10)
        ->get();

    return response()->json(['data' => $rows]);
}

public function recycleLeads(Request $request): JsonResponse
{
    $user = $request->user();
    [$from, $to] = $this->monthRange($request);

    $query = DB::table('customers')
        ->selectRaw('revived_from_type')
        ->selectRaw('COUNT(*) as total')
        ->where('is_recycled_lead', 1)
        ->whereBetween('recycled_at', [$from, $to]);

    if (!$this->isPrivileged($user)) {
        $query->where('recycled_by', $user->id);
    }

    $rows = $query
        ->groupBy('revived_from_type')
        ->get();

    return response()->json(['data' => $rows]);
}

public function agingPipeline(Request $request): JsonResponse
{
    $user = $request->user();

    $query = $this->currentAssignmentsBase()
        ->selectRaw('customers.status')
        ->selectRaw("
            SUM(CASE WHEN DATEDIFF(CURDATE(), customers.created_at) <= 7 THEN 1 ELSE 0 END) as bucket_7
        ")
        ->selectRaw("
            SUM(CASE WHEN DATEDIFF(CURDATE(), customers.created_at) BETWEEN 8 AND 14 THEN 1 ELSE 0 END) as bucket_14
        ")
        ->selectRaw("
            SUM(CASE WHEN DATEDIFF(CURDATE(), customers.created_at) BETWEEN 15 AND 30 THEN 1 ELSE 0 END) as bucket_30
        ")
        ->selectRaw("
            SUM(CASE WHEN DATEDIFF(CURDATE(), customers.created_at) > 30 THEN 1 ELSE 0 END) as bucket_over_30
        ")
        ->whereNotIn('customers.status', ['contracted', 'lost']);

    if (!$this->isPrivileged($user)) {
        $query->where('ca.user_id', $user->id);
    }

    $rows = $query
        ->groupBy('customers.status')
        ->orderBy('customers.status')
        ->get();

    return response()->json(['data' => $rows]);
}
}