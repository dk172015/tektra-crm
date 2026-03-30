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
            'project_code' => ['nullable', 'string', 'max:255'],
            'building_name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'floor' => ['nullable', 'string', 'max:100'],
            'area' => ['nullable', 'numeric', 'min:0'],
            'rental_price' => ['nullable', 'numeric', 'min:0'],
            'contract_term_months' => ['nullable', 'integer', 'min:1'],
            'deposit_date' => ['nullable', 'date'],
            'first_payment_date' => ['nullable', 'date'],
            'brokerage_fee' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string'],
            'closer_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $actor = $request->user();

        $closerUserId = $actor->isAdmin()
            ? ($validated['closer_user_id'] ?? null)
            : $actor->id;

        if (!$closerUserId) {
            return response()->json([
                'message' => 'Vui lòng chọn sale chốt hợp đồng.',
            ], 422);
        }

        $deal = $customer->deals()->create([
            'created_by' => $actor->id,
            'closer_user_id' => $closerUserId,
            'project_code' => $validated['project_code'] ?? null,
            'building_name' => $validated['building_name'],
            'address' => $validated['address'] ?? null,
            'floor' => $validated['floor'] ?? null,
            'area' => $validated['area'] ?? null,
            'rental_price' => $validated['rental_price'] ?? null,
            'contract_term_months' => $validated['contract_term_months'] ?? null,
            'deposit_date' => $validated['deposit_date'] ?? null,
            'first_payment_date' => $validated['first_payment_date'] ?? null,
            'brokerage_fee' => $validated['brokerage_fee'] ?? null,
            'note' => $validated['note'] ?? null,
            'status' => 'won',
            'signed_at' => now(),
        ]);

        $oldStatus = $customer->status;

        $customer->update([
            'status' => 'contracted',
        ]);

        $summaryParts = [
            'Chốt hợp đồng',
            $deal->building_name ? "tại {$deal->building_name}" : null,
            $deal->project_code ? "(Mã dự án: {$deal->project_code})" : null,
            $deal->rental_price !== null
                ? 'Giá thuê: ' . number_format((float) $deal->rental_price, 0, ',', '.') . ' VND'
                : null,
            $deal->brokerage_fee !== null
                ? 'Phí môi giới: ' . number_format((float) $deal->brokerage_fee, 0, ',', '.') . ' VND'
                : null,
            $deal->deposit_date ? 'Ngày đặt cọc: ' . $deal->deposit_date->format('d/m/Y') : null,
            $deal->first_payment_date ? 'Thanh toán kỳ đầu: ' . $deal->first_payment_date->format('d/m/Y') : null,
        ];

        $content = implode('. ', array_filter($summaryParts));
        if ($content !== '') {
            $content .= '.';
        }

        if ($deal->note) {
            $content .= ' Ghi chú: ' . $deal->note;
        }

        $customer->activities()->create([
            'user_id' => $actor->id,
            'type' => 'deal_closed',
            'content' => $content ?: 'Chốt hợp đồng.',
            'activity_time' => now(),
        ]);

        if ($oldStatus !== 'contracted') {
            $customer->activities()->create([
                'user_id' => $actor->id,
                'type' => 'status_change',
                'content' => "Chuyển trạng thái từ {$oldStatus} sang contracted.",
                'activity_time' => now(),
            ]);
        }

        return response()->json([
            'message' => 'Đã chốt hợp đồng thành công.',
            'data' => $deal->load([
                'customer:id,company_name,contact_name,phone,email',
                'creator:id,name',
                'closer:id,name',
            ]),
        ], 201);
    }
    public function show(Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);

        $deal = $customer->deals()
            ->with([
                'creator:id,name',
                'closer:id,name',
                'customer:id,company_name,contact_name,phone,email',
            ])
            ->latest('id')
            ->first();

        if (!$deal) {
            return response()->json([
                'message' => 'Khách hàng chưa có dữ liệu hợp đồng.',
            ], 404);
        }

        return response()->json($deal);
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('update', $customer);

        if (!$request->user()->isAdmin()) {
            return response()->json([
                'message' => 'Chỉ admin mới được sửa thông tin hợp đồng.',
            ], 403);
        }

        $deal = $customer->deals()->latest('id')->first();

        if (!$deal) {
            return response()->json([
                'message' => 'Khách hàng chưa có dữ liệu hợp đồng.',
            ], 404);
        }

        $validated = $request->validate([
            'project_code' => ['nullable', 'string', 'max:255'],
            'building_name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'floor' => ['nullable', 'string', 'max:100'],
            'area' => ['nullable', 'numeric', 'min:0'],
            'rental_price' => ['nullable', 'numeric', 'min:0'],
            'contract_term_months' => ['nullable', 'integer', 'min:1'],
            'deposit_date' => ['nullable', 'date'],
            'first_payment_date' => ['nullable', 'date'],
            'brokerage_fee' => ['nullable', 'numeric', 'min:0'],
            'closer_user_id' => ['required', 'integer', 'exists:users,id'],
            'note' => ['nullable', 'string'],
        ]);

        $deal->update([
            'project_code' => $validated['project_code'] ?? null,
            'building_name' => $validated['building_name'],
            'address' => $validated['address'] ?? null,
            'floor' => $validated['floor'] ?? null,
            'area' => $validated['area'] ?? null,
            'rental_price' => $validated['rental_price'] ?? null,
            'contract_term_months' => $validated['contract_term_months'] ?? null,
            'deposit_date' => $validated['deposit_date'] ?? null,
            'first_payment_date' => $validated['first_payment_date'] ?? null,
            'brokerage_fee' => $validated['brokerage_fee'] ?? null,
            'closer_user_id' => $validated['closer_user_id'],
            'note' => $validated['note'] ?? null,
        ]);

        $customer->activities()->create([
            'user_id' => $request->user()->id,
            'type' => 'deal_updated',
            'content' => 'Admin đã cập nhật thông tin hợp đồng đã chốt.',
            'activity_time' => now(),
        ]);

        return response()->json([
            'message' => 'Đã cập nhật thông tin hợp đồng.',
            'data' => $deal->fresh([
                'customer:id,company_name,contact_name,phone,email',
                'creator:id,name',
                'closer:id,name',
            ]),
        ]);
    }
}