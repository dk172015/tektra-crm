<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerLossController extends Controller
{
    public function store(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('update', $customer);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'competitor_name' => ['nullable', 'string', 'max:255'],
            'lost_price' => ['nullable', 'numeric', 'min:0'],
            'lost_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string'],
        ]);

        $loss = $customer->losses()->create([
            'created_by' => $request->user()->id,
            'reason' => $validated['reason'],
            'competitor_name' => $validated['competitor_name'] ?? null,
            'lost_price' => $validated['lost_price'] ?? null,
            'lost_at' => $validated['lost_at'] ?? now(),
            'note' => $validated['note'] ?? null,
        ]);

        $oldStatus = $customer->status;

        $customer->update([
            'status' => 'lost',
        ]);

        $content = "Mất khách. Lý do: {$loss->reason}.";
        if ($loss->competitor_name) {
            $content .= " Đối thủ: {$loss->competitor_name}.";
        }
        if ($loss->lost_price !== null) {
            $content .= ' Giá bên khác: ' . number_format((float) $loss->lost_price, 0, ',', '.') . ' VND.';
        }
        if ($loss->note) {
            $content .= " Ghi chú: {$loss->note}";
        }

        $customer->activities()->create([
            'user_id' => $request->user()->id,
            'type' => 'customer_lost',
            'content' => $content,
            'activity_time' => now(),
        ]);

        if ($oldStatus !== 'lost') {
            $customer->activities()->create([
                'user_id' => $request->user()->id,
                'type' => 'status_change',
                'content' => "Chuyển trạng thái từ {$oldStatus} sang lost.",
                'activity_time' => now(),
            ]);
        }

        return response()->json([
            'message' => 'Đã cập nhật mất khách.',
            'data' => $loss,
        ], 201);
    }
}