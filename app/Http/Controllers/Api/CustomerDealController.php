<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerDealController extends Controller
{
    public function store(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('update', $customer);

        $validated = $request->validate([
            'contract_code' => ['nullable', 'string', 'max:255'],
            'building_name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'area' => ['nullable', 'numeric', 'min:0'],
            'monthly_revenue' => ['required', 'numeric', 'min:0'],
            'lease_term_months' => ['nullable', 'integer', 'min:1'],
            'signed_date' => ['nullable', 'date'],
            'start_date' => ['nullable', 'date'],
            'note' => ['nullable', 'string'],
        ]);

        $deal = $customer->deals()->create([
            ...$validated,
            'created_by' => $request->user()->id,
            'status' => 'won',
        ]);

        $customer->update([
            'status' => 'contracted',
        ]);

        $customer->activities()->create([
            'user_id' => $request->user()->id,
            'type' => 'deal_closed',
            'content' => 'Chốt hợp đồng mặt bằng. Doanh thu: ' . number_format((float)$validated['monthly_revenue'], 0, ',', '.') . ' VND.',
            'activity_time' => now(),
        ]);

        return response()->json([
            'message' => 'Đã chốt hợp đồng thành công.',
            'data' => $deal->load('creator:id,name'),
        ], 201);
    }
}