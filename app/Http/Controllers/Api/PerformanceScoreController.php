<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PerformanceScoreController extends Controller
{
    private function isPrivileged($user): bool
    {
        return in_array($user->role, ['admin', 'leader'], true);
    }

    private function selfFoundLeadSourceId(): ?int
    {
        return DB::table('lead_sources')
            ->where('code', 'sales_self_gen')
            ->value('id');
    }

    private function getMonthRange(Request $request): array
    {
        $month = $request->input('month');

        if (!$month) {
            $now = Carbon::now();
            return [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()];
        }

        return [
            Carbon::createFromFormat('Y-m', $month)->startOfMonth(),
            Carbon::createFromFormat('Y-m', $month)->endOfMonth(),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        [$from, $to] = $this->getMonthRange($request);
        $selfFoundId = $this->selfFoundLeadSourceId();

        $weights = [
            'self_found' => (float) $request->input('w_self_found', 3),
            'company_assigned' => (float) $request->input('w_company_assigned', 1),
            'main' => (float) $request->input('w_main', 4),
            'support' => (float) $request->input('w_support', 1),
            'deal' => (float) $request->input('w_deal', 8),
            'revenue' => (float) $request->input('w_revenue', 0.000001), // 1 điểm / 1,000,000
            'sales_volume' => (float) $request->input('w_sales_volume', 0.0000005), // 0.5 điểm / 1,000,000
            'transferred_out_penalty' => (float) $request->input('w_transferred_out_penalty', 1),
        ];

        $salesQuery = DB::table('users')
            ->select('id', 'name', 'avatar', 'role')
            ->whereIn('role', ['admin', 'leader', 'sale']);

        if (!$this->isPrivileged($user)) {
            $salesQuery->where('id', $user->id);
        } elseif ($request->filled('sale_id')) {
            $salesQuery->where('id', $request->input('sale_id'));
        }

        $sales = $salesQuery->orderBy('name')->get();

        $rows = $sales->map(function ($sale) use ($from, $to, $selfFoundId, $weights) {
            $saleId = (int) $sale->id;

            $everAssignedBase = DB::table('customer_assignments as ca')
                ->join('customers', 'customers.id', '=', 'ca.customer_id')
                ->where('ca.user_id', $saleId)
                ->whereBetween('ca.assigned_at', [$from, $to]);

            $selfFoundTotal = $selfFoundId
                ? (clone $everAssignedBase)
                    ->where('customers.lead_source_id', $selfFoundId)
                    ->distinct()
                    ->count('ca.customer_id')
                : 0;

            $everAssignedTotal = (clone $everAssignedBase)
                ->distinct()
                ->count('ca.customer_id');

            $companyAssignedTotal = $everAssignedTotal - $selfFoundTotal;

            $mainBase = DB::table('customer_assignments as ca')
                ->join('customers', 'customers.id', '=', 'ca.customer_id')
                ->where('ca.user_id', $saleId)
                ->where('ca.is_active', 1)
                ->where('ca.is_primary', 1)
                ->whereBetween('ca.assigned_at', [$from, $to]);

            $mainTotal = (clone $mainBase)->distinct()->count('ca.customer_id');

            $supportBase = DB::table('customer_assignments as ca')
                ->join('customers', 'customers.id', '=', 'ca.customer_id')
                ->where('ca.user_id', $saleId)
                ->where('ca.is_active', 1)
                ->where('ca.is_primary', 0)
                ->whereBetween('ca.assigned_at', [$from, $to]);

            $supportTotal = (clone $supportBase)->distinct()->count('ca.customer_id');

            $transferredOutBase = DB::table('customer_assignments as ca')
                ->where('ca.user_id', $saleId)
                ->where('ca.is_active', 0)
                ->whereBetween('ca.ended_at', [$from, $to]);

            $transferredOutTotal = (clone $transferredOutBase)->distinct()->count('ca.customer_id');

            $dealBase = DB::table('customer_deals')
                ->where('customer_deals.closer_user_id', $saleId)
                ->whereNotNull('customer_deals.deposit_date')
                ->whereBetween('customer_deals.deposit_date', [$from->toDateString(), $to->toDateString()]);

            $dealTotal = (clone $dealBase)->count();

            $revenueTotal = (float) (clone $dealBase)->sum(DB::raw('COALESCE(customer_deals.final_revenue, 0)'));
            $salesVolumeTotal = (float) (clone $dealBase)->sum(DB::raw('COALESCE(customer_deals.sales_volume, 0)'));

            $scoreSelfFound = $selfFoundTotal * $weights['self_found'];
            $scoreCompanyAssigned = $companyAssignedTotal * $weights['company_assigned'];
            $scoreMain = $mainTotal * $weights['main'];
            $scoreSupport = $supportTotal * $weights['support'];
            $scoreDeal = $dealTotal * $weights['deal'];
            $scoreRevenue = $revenueTotal * $weights['revenue'];
            $scoreSalesVolume = $salesVolumeTotal * $weights['sales_volume'];
            $scorePenalty = $transferredOutTotal * $weights['transferred_out_penalty'];

            $totalScore =
                $scoreSelfFound +
                $scoreCompanyAssigned +
                $scoreMain +
                $scoreSupport +
                $scoreDeal +
                $scoreRevenue +
                $scoreSalesVolume -
                $scorePenalty;

            return [
                'user_id' => $saleId,
                'user_name' => $sale->name,
                'avatar' => $sale->avatar,

                'self_found_total' => $selfFoundTotal,
                'company_assigned_total' => $companyAssignedTotal,
                'main_total' => $mainTotal,
                'support_total' => $supportTotal,
                'transferred_out_total' => $transferredOutTotal,
                'deal_total' => $dealTotal,
                'revenue_total' => round($revenueTotal, 2),
                'sales_volume_total' => round($salesVolumeTotal, 2),

                'score_breakdown' => [
                    'self_found' => round($scoreSelfFound, 2),
                    'company_assigned' => round($scoreCompanyAssigned, 2),
                    'main' => round($scoreMain, 2),
                    'support' => round($scoreSupport, 2),
                    'deal' => round($scoreDeal, 2),
                    'revenue' => round($scoreRevenue, 2),
                    'sales_volume' => round($scoreSalesVolume, 2),
                    'transferred_out_penalty' => round($scorePenalty, 2),
                ],

                'total_score' => round($totalScore, 2),
            ];
        })
        ->sortByDesc('total_score')
        ->values()
        ->map(function ($row, $index) {
            $row['rank'] = $index + 1;
            return $row;
        })
        ->values();

        return response()->json([
            'data' => $rows,
            'weights' => $weights,
            'month' => $request->input('month', now()->format('Y-m')),
        ]);
    }
}