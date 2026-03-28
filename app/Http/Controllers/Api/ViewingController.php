<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\CustomerWarningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ViewingController extends Controller
{
    public function __construct(
        private CustomerWarningService $warningService
    ) {
    }

    public function store(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('update', $customer);

        $validated = $request->validate([
            'property_id' => ['nullable', 'integer', 'exists:properties,id'],
            'building_name' => ['required_without:property_id', 'nullable', 'string', 'max:255'],
            'address' => ['required_without:property_id', 'nullable', 'string', 'max:255'],
            'viewing_time' => ['required', 'date'],
            'status' => ['nullable', 'in:scheduled,done,cancelled'],
            'note' => ['nullable', 'string'],
        ]);

        $viewing = $customer->viewings()->create([
            'property_id' => $validated['property_id'] ?? null,
            'building_name' => $validated['building_name'] ?? null,
            'address' => $validated['address'] ?? null,
            'viewing_time' => $validated['viewing_time'],
            'status' => $validated['status'] ?? 'scheduled',
            'note' => $validated['note'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        $displayName = $viewing->building_name ?: 'Mặt bằng đã chọn';
        $displayAddress = $viewing->address ?: 'Không có địa chỉ';
        $displayTime = $viewing->viewing_time?->format('d/m/Y H:i') ?? now()->format('d/m/Y H:i');

        $content = "Tạo lịch đi xem: {$displayName} - {$displayAddress}. Thời gian: {$displayTime}.";
        if (!empty($validated['note'])) {
            $content .= " Ghi chú: {$validated['note']}";
        }

        $customer->activities()->create([
            'user_id' => $request->user()->id,
            'type' => 'site_visit',
            'content' => $content,
            'activity_time' => now(),
        ]);

        if ($customer->warning_level) {
            $message = $customer->warning_level === 'red'
                ? 'Admin xử lý cảnh báo đỏ bằng tạo lịch đi xem.'
                : 'Xử lý cảnh báo vàng bằng tạo lịch đi xem.';

            $this->warningService->resolveByAction(
                $customer,
                $request->user(),
                'site_visit',
                $message
            );
        }

        return response()->json(
            $viewing->load([
                'property:id,building_name,address,district,area,price_per_m2,status',
                'creator:id,name',
            ]),
            201
        );
    }
}