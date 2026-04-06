<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerReportController extends Controller
{
    private function isPrivileged($user): bool
    {
        return in_array($user->role, ['admin', 'leader'], true);
    }

    private function dateRange(Request $request): array
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

    private function applySaleScope($query, Request $request, string $assignmentUserColumn = 'ca.user_id')
    {
        $user = $request->user();

        if (!$this->isPrivileged($user)) {
            $query->where($assignmentUserColumn, $user->id);
            return $query;
        }

        if ($request->filled('sale_id')) {
            $query->where($assignmentUserColumn, $request->input('sale_id'));
        }

        return $query;
    }

    public function summary(Request $request): JsonResponse
    {
        [$from, $to] = $this->dateRange($request);

        $currentAssignedQuery = $this->applySaleScope(
            $this->currentAssignmentsBase(),
            $request
        );
        $this->applyCustomerGroupFilter($currentAssignedQuery, $request, 'customers');

        $processingQuery = $this->applySaleScope(
            $this->currentAssignmentsBase()->whereNotIn('customers.status', ['contracted', 'lost']),
            $request
        );
        $this->applyCustomerGroupFilter($processingQuery, $request, 'customers');

        $assignedInPeriodQuery = DB::table('customer_assignments')
            ->join('customers', 'customers.id', '=', 'customer_assignments.customer_id')
            ->whereBetween('customer_assignments.created_at', [$from, $to]);

        $user = $request->user();
        if (!$this->isPrivileged($user)) {
            $assignedInPeriodQuery->where('customer_assignments.user_id', $user->id);
        } elseif ($request->filled('sale_id')) {
            $assignedInPeriodQuery->where('customer_assignments.user_id', $request->input('sale_id'));
        }

        $this->applyCustomerGroupFilter($assignedInPeriodQuery, $request, 'customers');

        $wonQuery = DB::table('customer_deals')
            ->join('customers', 'customers.id', '=', 'customer_deals.customer_id')
            ->whereNotNull('customer_deals.deposit_date')
            ->whereBetween('customer_deals.deposit_date', [$from->toDateString(), $to->toDateString()]);

        if (!$this->isPrivileged($user)) {
            $wonQuery->where('customer_deals.closer_user_id', $user->id);
        } elseif ($request->filled('sale_id')) {
            $wonQuery->where('customer_deals.closer_user_id', $request->input('sale_id'));
        }

        $this->applyCustomerGroupFilter($wonQuery, $request, 'customers');

        $lostQuery = DB::table('customer_losses')
            ->join('customers', 'customers.id', '=', 'customer_losses.customer_id')
            ->whereBetween('customer_losses.lost_at', [$from, $to]);

        if (!$this->isPrivileged($user)) {
            $lostQuery->where('customer_losses.created_by', $user->id);
        } elseif ($request->filled('sale_id')) {
            $lostQuery->where('customer_losses.created_by', $request->input('sale_id'));
        }

        $this->applyCustomerGroupFilter($lostQuery, $request, 'customers');

        $warningQuery = $this->applySaleScope(
            $this->currentAssignmentsBase(),
            $request
        );
        $this->applyCustomerGroupFilter($warningQuery, $request, 'customers');

        $assignedInPeriod = (int) (clone $assignedInPeriodQuery)->count();
        $wonInPeriod = (int) (clone $wonQuery)->count();

        return response()->json([
            'current_assigned_customers' => (int) (clone $currentAssignedQuery)->count(),
            'assigned_in_period' => $assignedInPeriod,
            'processing_customers' => (int) (clone $processingQuery)->count(),
            'won_in_period' => $wonInPeriod,
            'lost_in_period' => (int) (clone $lostQuery)->count(),
            'yellow_warnings' => (int) (clone $warningQuery)->where('customers.warning_level', 'yellow')->count(),
            'red_warnings' => (int) (clone $warningQuery)->where('customers.warning_level', 'red')->count(),
            'conversion_rate' => $assignedInPeriod > 0 ? round(($wonInPeriod / $assignedInPeriod) * 100, 2) : 0,
        ]);
    }

    public function pipeline(Request $request): JsonResponse
    {
        $query = $this->applySaleScope(
            $this->currentAssignmentsBase(),
            $request
        );

        $this->applyCustomerGroupFilter($query, $request, 'customers');

        $rows = $query
            ->selectRaw('customers.status, COUNT(customers.id) as total')
            ->groupBy('customers.status')
            ->orderBy('customers.status')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function bySale(Request $request): JsonResponse
    {
        $selfFoundId = $this->selfFoundLeadSourceId();

        $query = $this->applySaleScope(
            $this->currentAssignmentsBase(),
            $request
        );

        $this->applyCustomerGroupFilter($query, $request, 'customers');

        $rows = $query
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
            ")
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('total_customers')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function assignedInPeriod(Request $request): JsonResponse
    {
        [$from, $to] = $this->dateRange($request);

        $query = DB::table('customer_assignments')
            ->join('users', 'users.id', '=', 'customer_assignments.user_id')
            ->join('customers', 'customers.id', '=', 'customer_assignments.customer_id')
            ->whereBetween('customer_assignments.created_at', [$from, $to]);

        $user = $request->user();
        if (!$this->isPrivileged($user)) {
            $query->where('customer_assignments.user_id', $user->id);
        } elseif ($request->filled('sale_id')) {
            $query->where('customer_assignments.user_id', $request->input('sale_id'));
        }

        $this->applyCustomerGroupFilter($query, $request, 'customers');

        $rows = $query
            ->selectRaw('users.id as user_id, users.name as user_name, COUNT(customer_assignments.id) as total_assigned')
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('total_assigned')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function conversionBySale(Request $request): JsonResponse
    {
        [$from, $to] = $this->dateRange($request);
        $user = $request->user();

        $assignedQuery = DB::table('customer_assignments')
            ->join('users', 'users.id', '=', 'customer_assignments.user_id')
            ->join('customers', 'customers.id', '=', 'customer_assignments.customer_id')
            ->whereBetween('customer_assignments.created_at', [$from, $to]);

        $wonQuery = DB::table('customer_deals')
            ->join('users', 'users.id', '=', 'customer_deals.closer_user_id')
            ->join('customers', 'customers.id', '=', 'customer_deals.customer_id')
            ->whereNotNull('customer_deals.deposit_date')
            ->whereBetween('customer_deals.deposit_date', [$from->toDateString(), $to->toDateString()]);

        if (!$this->isPrivileged($user)) {
            $assignedQuery->where('customer_assignments.user_id', $user->id);
            $wonQuery->where('customer_deals.closer_user_id', $user->id);
        } elseif ($request->filled('sale_id')) {
            $assignedQuery->where('customer_assignments.user_id', $request->input('sale_id'));
            $wonQuery->where('customer_deals.closer_user_id', $request->input('sale_id'));
        }

        $this->applyCustomerGroupFilter($assignedQuery, $request, 'customers');
        $this->applyCustomerGroupFilter($wonQuery, $request, 'customers');

        $assignedRows = $assignedQuery
            ->selectRaw('users.id as user_id, users.name as user_name, COUNT(customer_assignments.id) as assigned_count')
            ->groupBy('users.id', 'users.name')
            ->get()
            ->keyBy('user_id');

        $wonRows = $wonQuery
            ->selectRaw('users.id as user_id, COUNT(customer_deals.id) as won_count')
            ->groupBy('users.id')
            ->get()
            ->keyBy('user_id');

        $result = collect($assignedRows)->map(function ($row, $userId) use ($wonRows) {
            $assigned = (int) $row->assigned_count;
            $won = (int) ($wonRows[$userId]->won_count ?? 0);

            return [
                'user_id' => (int) $row->user_id,
                'user_name' => $row->user_name,
                'assigned_count' => $assigned,
                'won_count' => $won,
                'conversion_rate' => $assigned > 0 ? round(($won / $assigned) * 100, 2) : 0,
            ];
        })->values()->sortByDesc('conversion_rate')->values();

        return response()->json(['data' => $result]);
    }

    public function warning(Request $request): JsonResponse
    {
        $base = $this->applySaleScope(
            $this->currentAssignmentsBase(),
            $request
        );

        $this->applyCustomerGroupFilter($base, $request, 'customers');

        $yellow = (clone $base)->where('customers.warning_level', 'yellow')->count();
        $red = (clone $base)->where('customers.warning_level', 'red')->count();

        return response()->json([
            'yellow_warnings' => $yellow,
            'red_warnings' => $red,
            'due_today' => 0,
        ]);
    }

    public function aging(Request $request): JsonResponse
    {
        $query = $this->applySaleScope(
            $this->currentAssignmentsBase()->whereNotIn('customers.status', ['contracted', 'lost']),
            $request
        );

        $this->applyCustomerGroupFilter($query, $request, 'customers');

        $rows = $query
            ->selectRaw('customers.status')
            ->selectRaw("SUM(CASE WHEN DATEDIFF(CURDATE(), customers.updated_at) <= 7 THEN 1 ELSE 0 END) as d7")
            ->selectRaw("SUM(CASE WHEN DATEDIFF(CURDATE(), customers.updated_at) BETWEEN 8 AND 14 THEN 1 ELSE 0 END) as d14")
            ->selectRaw("SUM(CASE WHEN DATEDIFF(CURDATE(), customers.updated_at) BETWEEN 15 AND 30 THEN 1 ELSE 0 END) as d30")
            ->selectRaw("SUM(CASE WHEN DATEDIFF(CURDATE(), customers.updated_at) > 30 THEN 1 ELSE 0 END) as d30plus")
            ->groupBy('customers.status')
            ->orderBy('customers.status')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function tabData(Request $request): JsonResponse
    {
        $tab = $request->input('tab', 'new_assignments');
        [$from, $to] = $this->dateRange($request);
        $user = $request->user();

        switch ($tab) {
            case 'new_assignments':
                $query = DB::table('customer_assignments')
                    ->join('users', 'users.id', '=', 'customer_assignments.user_id')
                    ->join('customers', 'customers.id', '=', 'customer_assignments.customer_id')
                    ->whereBetween('customer_assignments.created_at', [$from, $to]);

                if (!$this->isPrivileged($user)) {
                    $query->where('customer_assignments.user_id', $user->id);
                } elseif ($request->filled('sale_id')) {
                    $query->where('customer_assignments.user_id', $request->input('sale_id'));
                }

                $this->applyCustomerGroupFilter($query, $request, 'customers');

                $rows = $query
                    ->selectRaw('customer_assignments.id')
                    ->selectRaw('users.name as sale_name')
                    ->selectRaw('customers.company_name')
                    ->selectRaw('customers.contact_name')
                    ->selectRaw('customers.phone')
                    ->selectRaw('customer_assignments.created_at as assigned_at')
                    ->orderByDesc('customer_assignments.id')
                    ->paginate(20);
                break;

            case 'warning':
                $query = $this->applySaleScope(
                    $this->currentAssignmentsBase()->whereIn('customers.warning_level', ['yellow', 'red']),
                    $request
                );

                $this->applyCustomerGroupFilter($query, $request, 'customers');

                $rows = $query
                    ->selectRaw('customers.id')
                    ->selectRaw('users.name as sale_name')
                    ->selectRaw('customers.company_name')
                    ->selectRaw('customers.contact_name')
                    ->selectRaw('customers.phone')
                    ->selectRaw('customers.warning_level')
                    ->orderByDesc('customers.id')
                    ->paginate(20);
                break;

            case 'viewing':
                $query = $this->applySaleScope(
                    $this->currentAssignmentsBase()->where('customers.status', 'viewing'),
                    $request
                );

                $this->applyCustomerGroupFilter($query, $request, 'customers');

                $rows = $query
                    ->selectRaw('customers.id')
                    ->selectRaw('users.name as sale_name')
                    ->selectRaw('customers.company_name')
                    ->selectRaw('customers.contact_name')
                    ->selectRaw('customers.phone')
                    ->selectRaw('customers.status')
                    ->orderByDesc('customers.id')
                    ->paginate(20);
                break;

            case 'deals':
                $query = DB::table('customer_deals')
                    ->join('customers', 'customers.id', '=', 'customer_deals.customer_id')
                    ->join('users', 'users.id', '=', 'customer_deals.closer_user_id')
                    ->whereNotNull('customer_deals.deposit_date')
                    ->whereBetween('customer_deals.deposit_date', [$from->toDateString(), $to->toDateString()]);

                if (!$this->isPrivileged($user)) {
                    $query->where('customer_deals.closer_user_id', $user->id);
                } elseif ($request->filled('sale_id')) {
                    $query->where('customer_deals.closer_user_id', $request->input('sale_id'));
                }

                $this->applyCustomerGroupFilter($query, $request, 'customers');

                $rows = $query
                    ->selectRaw('customer_deals.id')
                    ->selectRaw('users.name as sale_name')
                    ->selectRaw('customers.company_name')
                    ->selectRaw('customers.contact_name')
                    ->selectRaw('customers.phone')
                    ->selectRaw('customer_deals.deposit_date')
                    ->selectRaw('COALESCE(customer_deals.final_revenue, customer_deals.net_revenue, 0) as revenue')
                    ->orderByDesc('customer_deals.id')
                    ->paginate(20);
                break;

            case 'lost':
                $query = DB::table('customer_losses')
                    ->join('customers', 'customers.id', '=', 'customer_losses.customer_id')
                    ->join('users', 'users.id', '=', 'customer_losses.created_by')
                    ->whereBetween('customer_losses.lost_at', [$from, $to]);

                if (!$this->isPrivileged($user)) {
                    $query->where('customer_losses.created_by', $user->id);
                } elseif ($request->filled('sale_id')) {
                    $query->where('customer_losses.created_by', $request->input('sale_id'));
                }

                $this->applyCustomerGroupFilter($query, $request, 'customers');

                $rows = $query
                    ->selectRaw('customer_losses.id')
                    ->selectRaw('users.name as sale_name')
                    ->selectRaw('customers.company_name')
                    ->selectRaw('customers.contact_name')
                    ->selectRaw('customers.phone')
                    ->selectRaw('customer_losses.reason')
                    ->selectRaw('customer_losses.lost_at')
                    ->orderByDesc('customer_losses.id')
                    ->paginate(20);
                break;

            default:
                $rows = collect([]);
                break;
        }

        return response()->json($rows);
    }
}