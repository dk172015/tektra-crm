<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\CustomerWarningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerActivityController extends Controller
{
    public function __construct(private CustomerWarningService $warningService) {
    }

    public function store(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('update', $customer);
        if (
            in_array($customer->status, ['contracted', 'lost'], true) &&
            !$request->user()->isAdmin()
        ) {
            return response()->json([
                'message' => 'Khách hàng ở trạng thái đã chốt hoặc mất khách, sale chỉ được xem.'
            ], 403);
        }

        $validated = $request->validate([
            'type' => ['required', 'string', 'max:50'],
            'content' => ['required', 'string'],
        ]);

        $activity = $customer->activities()->create([
            'user_id' => $request->user()->id,
            'type' => $validated['type'],
            'content' => $validated['content'],
            'activity_time' => now(),
        ]);

        if ($customer->warning_level) {
            $message = $this->buildResolveMessage($customer->warning_level, $validated['type']);

            $this->warningService->resolveByAction(
                $customer,
                $request->user(),
                $validated['type'],
                $message
            );
        }

        return response()->json(
            $activity->load('user:id,name'),
            201
        );
    }

    private function buildResolveMessage(?string $warningLevel, string $type): string
    {
        $actionLabel = match ($type) {
            'note' => 'ghi chú',
            'call' => 'gọi điện',
            'message' => 'nhắn tin',
            'meeting' => 'gặp mặt',
            'site_visit' => 'khảo sát / đi xem',
            'status_change' => 'cập nhật trạng thái',
            default => 'cập nhật hoạt động',
        };

        if ($warningLevel === 'red') {
            return "Admin xử lý cảnh báo đỏ bằng {$actionLabel}.";
        }

        return "Xử lý cảnh báo vàng bằng {$actionLabel}.";
    }
}