<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AssignmentStatusReportController extends Controller
{
    private function isPrivileged($user): bool
    {
        return in_array($user->role, ['admin', 'leader'], true);
    }

    private function getMonthRange($request)
    {
        $month = $request->input('month');

        if (!$month) {
            $now = Carbon::now();
            return [
                $now->copy()->startOfMonth(),
                $now->copy()->endOfMonth()
            ];
        }

        return [
            Carbon::createFromFormat('Y-m', $month)->startOfMonth(),
            Carbon::createFromFormat('Y-m', $month)->endOfMonth()
        ];
    }

    private function selfFoundLeadSourceId()
    {
        return DB::table('lead_sources')
            ->where('code', 'sales_self_gen')
            ->value('id');
    }

    public function summary(Request $request)
    {
        $user = $request->user();
        [$from, $to] = $this->getMonthRange($request);
        $selfFoundId = $this->selfFoundLeadSourceId();

        $salesQuery = DB::table('users')
            ->select('id', 'name')
            ->whereIn('role', ['admin', 'leader', 'sale']);

        if (!$this->isPrivileged($user)) {
            $salesQuery->where('id', $user->id);
        }

        $sales = $salesQuery->get();

        $rows = [];

        foreach ($sales as $sale) {
            $saleId = $sale->id;

            // MAIN
            $main = DB::table('customer_assignments as ca')
                ->join('customers', 'customers.id', '=', 'ca.customer_id')
                ->where('ca.user_id', $saleId)
                ->where('ca.is_active', 1)
                ->where('ca.is_primary', 1)
                ->whereBetween('ca.assigned_at', [$from, $to]);

            $mainTotal = (clone $main)->distinct()->count('ca.customer_id');

            $mainSelf = $selfFoundId
                ? (clone $main)->where('customers.lead_source_id', $selfFoundId)->distinct()->count('ca.customer_id')
                : 0;

            $mainCompany = $mainTotal - $mainSelf;

            // SUPPORT
            $support = DB::table('customer_assignments as ca')
                ->where('ca.user_id', $saleId)
                ->where('ca.is_active', 1)
                ->where('ca.is_primary', 0)
                ->whereBetween('ca.assigned_at', [$from, $to]);

            $supportTotal = (clone $support)->distinct()->count('ca.customer_id');

            // EVER
            $ever = DB::table('customer_assignments as ca')
                ->join('customers', 'customers.id', '=', 'ca.customer_id')
                ->where('ca.user_id', $saleId)
                ->whereBetween('ca.assigned_at', [$from, $to]);

            $everTotal = (clone $ever)->distinct()->count('ca.customer_id');

            $selfFoundTotal = $selfFoundId
                ? (clone $ever)->where('customers.lead_source_id', $selfFoundId)->distinct()->count('ca.customer_id')
                : 0;

            $companyTotal = $everTotal - $selfFoundTotal;

            // TRANSFERRED
            $transferred = DB::table('customer_assignments as ca')
                ->where('ca.user_id', $saleId)
                ->where('ca.is_active', 0)
                ->whereBetween('ca.ended_at', [$from, $to]);

            $transferredTotal = (clone $transferred)->distinct()->count('ca.customer_id');

            $rows[] = [
                'user_id' => $saleId,
                'user_name' => $sale->name,

                'self_found_total' => $selfFoundTotal,
                'company_assigned_total' => $companyTotal,

                'current_main_total' => $mainTotal,
                'current_main_self_found' => $mainSelf,
                'current_main_company' => $mainCompany,

                'current_support_total' => $supportTotal,

                'transferred_out_total' => $transferredTotal,
                'ever_assigned_total' => $everTotal,
            ];
        }

        return response()->json([
            'data' => $rows,
            'totals' => [
                'self_found_total' => collect($rows)->sum('self_found_total'),
                'company_assigned_total' => collect($rows)->sum('company_assigned_total'),
                'current_main_total' => collect($rows)->sum('current_main_total'),
                'current_main_company' => collect($rows)->sum('current_main_company'),
                'current_support_total' => collect($rows)->sum('current_support_total'),
                'transferred_out_total' => collect($rows)->sum('transferred_out_total'),
                'ever_assigned_total' => collect($rows)->sum('ever_assigned_total'),
            ]
        ]);
    }

    public function detail(Request $request)
    {
        $user = $request->user();
        [$from, $to] = $this->getMonthRange($request);

        $saleId = $this->isPrivileged($user)
            ? $request->input('sale_id', $user->id)
            : $user->id;

        $query = DB::table('customer_assignments as ca')
            ->join('customers', 'customers.id', '=', 'ca.customer_id')
            ->leftJoin('lead_sources', 'lead_sources.id', '=', 'customers.lead_source_id')
            ->where('ca.user_id', $saleId)
            ->whereBetween('ca.assigned_at', [$from, $to]);

        if ($request->type === 'current_main') {
            $query->where('ca.is_active', 1)->where('ca.is_primary', 1);
        }

        if ($request->type === 'current_support') {
            $query->where('ca.is_active', 1)->where('ca.is_primary', 0);
        }

        if ($request->type === 'transferred_out') {
            $query->where('ca.is_active', 0);
        }

        if ($request->filled('keyword')) {
            $query->where(function ($q) use ($request) {
                $q->where('customers.company_name', 'like', '%' . $request->keyword . '%')
                    ->orWhere('customers.contact_name', 'like', '%' . $request->keyword . '%')
                    ->orWhere('customers.phone', 'like', '%' . $request->keyword . '%');
            });
        }

        return response()->json(
            $query->select(
                'customers.company_name',
                'customers.contact_name',
                'customers.phone',
                'lead_sources.code as lead_source',
                'ca.is_primary',
                'ca.is_active',
                'ca.assigned_at',
                'ca.ended_at'
            )->paginate(20)
        );
    }
}